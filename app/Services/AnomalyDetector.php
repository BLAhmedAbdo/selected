<?php

namespace App\Services;

class AnomalyDetector
{
    public function detect(array $snapshot, array $baselines): array
    {
        $signals = [];

        foreach ($snapshot as $endpoint => $metrics) {
            $baseline = $baselines[$endpoint] ?? [
                'average_latency' => 0.0,
                'request_rate' => 0.0,
                'error_rate' => 0.0,
                'sample_count' => 0,
                'warm' => false,
            ];

            $warm = (bool) ($baseline['warm'] ?? false);
            $requestRate = (float) ($metrics['request_rate'] ?? 0.0);
            $errorRate = (float) ($metrics['error_rate'] ?? 0.0);
            $latency = (float) ($metrics['latency_p95'] ?? 0.0);
            $baselineRequestRate = (float) ($baseline['request_rate'] ?? 0.0);
            $baselineErrorRate = (float) ($baseline['error_rate'] ?? 0.0);
            $baselineLatency = (float) ($baseline['average_latency'] ?? 0.0);

            if ($warm && $baselineLatency > 0 && $latency > $baselineLatency * 3) {
                $signals[] = $this->signal('LATENCY_ANOMALY', $endpoint, 'high', 'latency_p95', $latency, $baselineLatency, 'p95 latency exceeded 3x baseline');
            }

            if ($requestRate > 0 && $errorRate > max(0.10, $baselineErrorRate * 3)) {
                $signals[] = $this->signal('ERROR_RATE_ANOMALY', $endpoint, $errorRate >= 0.50 ? 'critical' : 'high', 'error_rate', $errorRate, $baselineErrorRate, 'error rate breached threshold');
            }

            if ($warm && $baselineRequestRate > 0 && $requestRate > $baselineRequestRate * 2) {
                $signals[] = $this->signal('TRAFFIC_ANOMALY', $endpoint, 'medium', 'request_rate', $requestRate, $baselineRequestRate, 'traffic rate exceeded 2x baseline');
            }

            if ($requestRate > 0 && $errorRate >= 0.90) {
                $signals[] = $this->signal('ENDPOINT_SPECIFIC_ANOMALY', $endpoint, 'critical', 'endpoint_health', 1.0, 0.0, 'endpoint is failing almost all requests');
            }
        }

        return $signals;
    }

    private function signal(string $type, string $endpoint, string $severity, string $metric, float $observed, float $baseline, string $reason): array
    {
        return [
            'signal_type' => $type,
            'endpoint' => $endpoint,
            'severity' => $severity,
            'metric' => $metric,
            'observed' => round($observed, 6),
            'baseline' => round($baseline, 6),
            'reason' => $reason,
        ];
    }
}
