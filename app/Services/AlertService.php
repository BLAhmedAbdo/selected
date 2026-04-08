<?php

namespace App\Services;

class AlertService
{
    private string $alertsPath;

    public function __construct()
    {
        $this->alertsPath = storage_path('aiops/alerts.json');

        if (! is_dir(dirname($this->alertsPath))) {
            mkdir(dirname($this->alertsPath), 0777, true);
        }

        if (! file_exists($this->alertsPath)) {
            file_put_contents($this->alertsPath, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    public function emit(array $incident, bool $shouldAlert, object $console): void
    {
        if (! $shouldAlert) {
            $console->line('[ALERT SUPPRESSED] '.$incident['incident_id'].' '.$incident['incident_type']);
            return;
        }

        $payload = [
            'incident_id' => $incident['incident_id'],
            'incident_type' => $incident['incident_type'],
            'severity' => $incident['severity'],
            'timestamp' => $incident['detected_at'],
            'summary' => $incident['summary'],
        ];

        $console->error('[ALERT] '.json_encode($payload, JSON_UNESCAPED_SLASHES));

        $alerts = json_decode((string) file_get_contents($this->alertsPath), true);
        if (! is_array($alerts)) {
            $alerts = [];
        }
        $alerts[] = $payload;
        file_put_contents($this->alertsPath, json_encode($alerts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
