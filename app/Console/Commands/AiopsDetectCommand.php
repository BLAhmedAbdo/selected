<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use App\Services\AnomalyDetector;
use App\Services\BaselineService;
use App\Services\IncidentCorrelator;
use App\Services\IncidentRepository;
use App\Services\PrometheusClient;
use Illuminate\Console\Command;
use Throwable;

class AiopsDetectCommand extends Command
{
    protected $signature = 'aiops:detect';

    protected $description = 'Continuously detect anomalies from Prometheus metrics and generate incidents';

    public function handle(
        PrometheusClient $prometheusClient,
        BaselineService $baselineService,
        AnomalyDetector $anomalyDetector,
        IncidentCorrelator $incidentCorrelator,
        IncidentRepository $incidentRepository,
        AlertService $alertService,
    ): int {
        $intervalSeconds = (int) env('AIOPS_DETECT_INTERVAL_SECONDS', 25);
        $this->info('AIOps Detection Engine started. Polling every '.$intervalSeconds.' seconds.');

        while (true) {
            $this->newLine();
            $this->line('['.now()->toDateTimeString().'] Collecting Prometheus metrics...');

            try {
                $snapshot = $prometheusClient->snapshot();
                $baselines = $baselineService->getBaselines();

                $this->renderSnapshot($snapshot);

                $signals = $anomalyDetector->detect($snapshot, $baselines);
                $incidents = $incidentCorrelator->correlate($signals, $snapshot, $baselines);

                $currentFingerprints = [];
                foreach ($incidents as $incident) {
                    $currentFingerprints[] = $incident['fingerprint'];
                    $result = $incidentRepository->record($incident);
                    $alertService->emit($incident, $result['created'], $this);
                }

                $incidentRepository->resolveMissingIncidents($currentFingerprints);
                $baselineService->updateWithSnapshot($snapshot);

                if ($signals === []) {
                    $this->info('No anomalies detected in this cycle.');
                } else {
                    $this->warn('Detected '.count($signals).' abnormal signals and '.count($incidents).' correlated incidents.');
                }
            } catch (Throwable $throwable) {
                $this->error('Detection cycle failed: '.$throwable->getMessage());
            }

            sleep(max(20, min(30, $intervalSeconds)));
        }
    }

    private function renderSnapshot(array $snapshot): void
    {
        $rows = [];
        foreach ($snapshot as $endpoint => $metrics) {
            $rows[] = [
                $endpoint,
                number_format((float) $metrics['request_rate'], 3),
                number_format((float) $metrics['error_rate'] * 100, 2).'%',
                number_format((float) $metrics['latency_p95'], 3).'s',
                json_encode($metrics['error_categories'], JSON_UNESCAPED_SLASHES),
            ];
        }

        $this->table(
            ['Endpoint', 'Req/s', 'Error Rate', 'P95 Latency', 'Error Categories'],
            $rows
        );
    }
}
