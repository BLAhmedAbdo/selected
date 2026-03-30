<?php

namespace App\Services;

class IncidentCorrelator
{
    public function correlate(array $signals, array $snapshot, array $baselines): array
    {
        if ($signals === []) {
            return [];
        }

        $byEndpoint = [];
        foreach ($signals as $signal) {
            $byEndpoint[$signal['endpoint']][] = $signal;
        }

        $incidents = [];

        $trafficSignals = array_values(array_filter($signals, static fn (array $signal): bool => $signal['signal_type'] === 'TRAFFIC_ANOMALY'));
        if (count($trafficSignals) >= 2) {
            $incidents[] = $this->buildIncident('TRAFFIC_SURGE', $trafficSignals, $snapshot, $baselines, 'medium', 'Traffic surge detected across multiple endpoints');
        }

        $errorSignals = array_values(array_filter($signals, static fn (array $signal): bool => in_array($signal['signal_type'], ['ERROR_RATE_ANOMALY', 'ENDPOINT_SPECIFIC_ANOMALY'], true)));
        if (count($errorSignals) >= 2) {
            $severity = collect($errorSignals)->contains(fn (array $signal): bool => $signal['severity'] === 'critical') ? 'critical' : 'high';
            $incidents[] = $this->buildIncident('ERROR_STORM', $errorSignals, $snapshot, $baselines, $severity, 'Elevated error activity detected on multiple endpoints');
        }

        foreach ($byEndpoint as $endpoint => $endpointSignals) {
            $types = array_column($endpointSignals, 'signal_type');

            if (in_array('LATENCY_ANOMALY', $types, true) && in_array('ERROR_RATE_ANOMALY', $types, true)) {
                $incidents[] = $this->buildIncident('SERVICE_DEGRADATION', $endpointSignals, $snapshot, $baselines, 'high', 'Correlated latency and error degradation detected');
                continue;
            }

            if (count($endpointSignals) >= 2 || in_array('ENDPOINT_SPECIFIC_ANOMALY', $types, true)) {
                $severity = in_array('ENDPOINT_SPECIFIC_ANOMALY', $types, true) ? 'critical' : 'high';
                $incidents[] = $this->buildIncident('LOCALIZED_ENDPOINT_FAILURE', $endpointSignals, $snapshot, $baselines, $severity, 'Failure isolated to a specific endpoint');
                continue;
            }

            if (in_array('LATENCY_ANOMALY', $types, true)) {
                $incidents[] = $this->buildIncident('LATENCY_SPIKE', $endpointSignals, $snapshot, $baselines, 'high', 'Latency spike detected for endpoint');
                continue;
            }

            if (in_array('TRAFFIC_ANOMALY', $types, true)) {
                $incidents[] = $this->buildIncident('TRAFFIC_SURGE', $endpointSignals, $snapshot, $baselines, 'medium', 'Traffic surge detected for endpoint');
            }
        }

        $deduped = [];
        foreach ($incidents as $incident) {
            $deduped[$incident['fingerprint']] = $incident;
        }

        return array_values($deduped);
    }

    private function buildIncident(string $type, array $signals, array $snapshot, array $baselines, string $severity, string $summary): array
    {
        $endpoints = array_values(array_unique(array_map(static fn (array $signal): string => $signal['endpoint'], $signals)));
        sort($endpoints);
        $fingerprint = sha1($type.'|'.implode('|', $endpoints));

        $baselineValues = [];
        $observedValues = [];
        foreach ($endpoints as $endpoint) {
            $baselineValues[$endpoint] = $baselines[$endpoint] ?? [];
            $observedValues[$endpoint] = $snapshot[$endpoint] ?? [];
        }

        return [
            'incident_id' => 'INC-'.strtoupper(substr($fingerprint, 0, 12)),
            'incident_type' => $type,
            'severity' => $severity,
            'status' => 'open',
            'detected_at' => now()->toIso8601String(),
            'affected_service' => config('app.name', 'laravel-service'),
            'affected_endpoints' => $endpoints,
            'triggering_signals' => $signals,
            'baseline_values' => $baselineValues,
            'observed_values' => $observedValues,
            'summary' => $summary,
            'fingerprint' => $fingerprint,
        ];
    }
}
