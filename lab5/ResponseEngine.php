<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ResponseEngine
{
    private string $activePath;
    private string $responsesPath;
    
    public function __construct()
    {
        $this->activePath = storage_path('aiops/active_incidents.json');
        $this->responsesPath = storage_path('aiops/responses.json');

        if (!is_dir(storage_path('aiops'))) {
            mkdir(storage_path('aiops'), 0755, true);
        }
    }

    public function process(): array
    {
        if (!file_exists($this->activePath)) {
            return [];
        }
        $activeIncidents = json_decode(file_get_contents($this->activePath), true) ?? [];
        $responses = $this->getResponses();
        
        $actionsTaken = [];

        foreach ($activeIncidents as $fingerprint => $data) {
            $status = $data['status'] ?? 'open';
            $incidentId = $data['incident_id'];
            $incidentType = $data['incident_type'] ?? 'DEFAULT';

            // We only respond to 'open' incidents
            if ($status !== 'open') {
                continue;
            }

            // Check how many times we've responded to this incident
            $previousResponses = array_filter($responses, fn($r) => $r['incident_id'] === $incidentId);
            $responseCount = count($previousResponses);

            if ($responseCount === 0) {
                // First time responding
                $action = $this->executeAction($incidentId, $incidentType);
                $actionsTaken[] = $action;
                $responses[] = $action; // update memory
            } elseif ($responseCount === 1) {
                $lastResponse = end($previousResponses);
                if ($lastResponse['result'] === 'failed') {
                    // It failed previously, escalate
                    $action = $this->executeEscalation($incidentId, 'Automated action failed previously.');
                    $actionsTaken[] = $action;
                    $responses[] = $action;
                } else {
                    // It succeeded previously but is STILL open (anomaly persists)
                    $action = $this->executeEscalation($incidentId, 'Anomaly persists after automated action.');
                    $actionsTaken[] = $action;
                    $responses[] = $action;
                }
            }
        }
        
        return $actionsTaken;
    }

    private function getResponses(): array
    {
        if (file_exists($this->responsesPath)) {
            return json_decode(file_get_contents($this->responsesPath), true) ?? [];
        }
        return [];
    }
    
    private function saveResponse(array $response): void
    {
        $responses = $this->getResponses();
        $responses[] = $response;
        file_put_contents($this->responsesPath, json_encode($responses, JSON_PRETTY_PRINT));
    }

    private function executeAction(string $incidentId, string $incidentType): array
    {
        $policies = config('aiops.response_policies', []);
        $actionTaken = $policies[$incidentType] ?? $policies['DEFAULT'] ?? 'escalate';

        if ($actionTaken === 'escalate') {
            return $this->executeEscalation($incidentId, 'No valid policy found, defaulting to escalate.');
        }

        // Simulate Action Execution
        $isSuccess = rand(1, 100) > 20; // 80% chance to succeed
        $result = $isSuccess ? 'success' : 'failed';
        $notes = $isSuccess ? "Simulated `$actionTaken` successfully." : "Failed to execute `$actionTaken`.";

        return $this->recordResponse($incidentId, $actionTaken, $result, $notes);
    }

    private function executeEscalation(string $incidentId, string $reason): array
    {
        return $this->recordResponse($incidentId, 'CRITICAL_ALERT', 'success', "Escalated: $reason");
    }

    private function recordResponse(string $incidentId, string $actionTaken, string $result, string $notes): array
    {
        $response = [
            'incident_id' => $incidentId,
            'action_taken' => $actionTaken,
            'timestamp' => now()->toIso8601String(),
            'result' => $result,
            'notes' => $notes,
        ];

        $this->saveResponse($response);
        return $response;
    }
}
