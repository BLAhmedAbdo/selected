<?php

namespace App\Services;

class IncidentRepository
{
    private string $incidentsPath;

    private string $activeIncidentsPath;

    public function __construct()
    {
        $baseDir = storage_path('aiops');
        if (! is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }

        $this->incidentsPath = $baseDir.'/incidents.json';
        $this->activeIncidentsPath = $baseDir.'/active_incidents.json';

        if (! file_exists($this->incidentsPath)) {
            file_put_contents($this->incidentsPath, json_encode([], JSON_PRETTY_PRINT));
        }

        if (! file_exists($this->activeIncidentsPath)) {
            file_put_contents($this->activeIncidentsPath, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    public function record(array $incident): array
    {
        $active = $this->load($this->activeIncidentsPath);
        $history = $this->load($this->incidentsPath);
        $fingerprint = $incident['fingerprint'];

        $isNew = ! isset($active[$fingerprint]);

        if ($isNew) {
            $active[$fingerprint] = [
                'incident_id' => $incident['incident_id'],
                'incident_type' => $incident['incident_type'],
                'severity' => $incident['severity'],
                'status' => 'open',
                'first_seen_at' => $incident['detected_at'],
                'last_seen_at' => $incident['detected_at'],
                'affected_endpoints' => $incident['affected_endpoints'],
            ];
            $history[] = $incident;
        } else {
            $active[$fingerprint]['last_seen_at'] = $incident['detected_at'];
            $active[$fingerprint]['severity'] = $incident['severity'];
        }

        $this->save($this->activeIncidentsPath, $active);
        $this->save($this->incidentsPath, $history);

        return [
            'created' => $isNew,
            'incident' => $incident,
        ];
    }

    public function resolveMissingIncidents(array $currentFingerprints): void
    {
        $active = $this->load($this->activeIncidentsPath);
        $changed = false;

        foreach ($active as $fingerprint => $incident) {
            if (! in_array($fingerprint, $currentFingerprints, true) && ($incident['status'] ?? 'open') === 'open') {
                $active[$fingerprint]['status'] = 'resolved';
                $active[$fingerprint]['resolved_at'] = now()->toIso8601String();
                $changed = true;
            }
        }

        if ($changed) {
            $this->save($this->activeIncidentsPath, $active);
        }
    }

    private function load(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function save(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
