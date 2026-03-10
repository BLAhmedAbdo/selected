<?php

namespace App\Services;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

class PrometheusService
{
    private CollectorRegistry $registry;

    public function __construct()
    {
        $this->registry = new CollectorRegistry(new InMemory());
    }

    public function buildFromLogFile(): void
    {
        $logFile = storage_path('logs/aiops.log');

        if (!file_exists($logFile)) {
            return;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $requestCounter = $this->registry->getOrRegisterCounter(
            'app',
            'http_requests_total',
            'Total number of HTTP requests',
            ['method', 'path', 'status']
        );

        $errorCounter = $this->registry->getOrRegisterCounter(
            'app',
            'http_errors_total',
            'Total number of HTTP errors by category',
            ['method', 'path', 'error_category']
        );

        $histogram = $this->registry->getOrRegisterHistogram(
            'app',
            'http_request_duration_seconds',
            'HTTP request duration in seconds',
            ['method', 'path'],
            [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
        );

        foreach ($lines as $line) {
            $jsonStart = strpos($line, '{');

            if ($jsonStart === false) {
                continue;
            }

            $json = substr($line, $jsonStart);
            $record = json_decode($json, true);

            if (!is_array($record)) {
                continue;
            }

            $method = (string) ($record['method'] ?? 'UNKNOWN');
            $path = (string) ($record['path'] ?? 'unknown');
            $status = (string) ($record['status_code'] ?? '0');
            $errorCategory = $record['error_category'] ?? null;
            $latencyMs = (float) ($record['latency_ms'] ?? 0);

            if ($path === 'metrics') {
                continue;
            }

            $requestCounter->inc([$method, $path, $status]);

            if ($errorCategory !== null) {
                $errorCounter->inc([$method, $path, $errorCategory]);
            }

            $histogram->observe($latencyMs / 1000, [$method, $path]);
        }
    }

    public function render(): string
    {
        $this->buildFromLogFile();

        $renderer = new RenderTextFormat();

        return $renderer->render($this->registry->getMetricFamilySamples());
    }
}