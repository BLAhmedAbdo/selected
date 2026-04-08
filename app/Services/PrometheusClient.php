<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PrometheusClient
{
    public function __construct(
        private readonly string $baseUrl = '',
    ) {
    }

    public function query(string $promql): array
    {
        $url = rtrim($this->baseUrl !== '' ? $this->baseUrl : (string) config('services.prometheus.base_url', env('PROMETHEUS_BASE_URL', 'http://localhost:9090')), '/').'/api/v1/query';

        $response = Http::timeout(10)->retry(2, 200)->get($url, [
            'query' => $promql,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Prometheus query failed with HTTP '.$response->status());
        }

        $payload = $response->json();

        if (($payload['status'] ?? null) !== 'success') {
            throw new RuntimeException('Prometheus query returned non-success status.');
        }

        return $payload['data']['result'] ?? [];
    }

    public function getRequestRateByEndpoint(): array
    {
        return $this->vectorToEndpointMap(
            $this->query('sum by (path) (rate(app_http_requests_total[2m]))'),
            'path'
        );
    }

    public function getErrorRateByEndpoint(): array
    {
        $requests = $this->getRequestRateByEndpoint();
        $errors = $this->vectorToEndpointMap(
            $this->query('sum by (path) (rate(app_http_errors_total[2m]))'),
            'path'
        );

        $results = [];
        foreach ($this->knownEndpoints() as $endpoint) {
            $requestRate = (float) ($requests[$endpoint] ?? 0.0);
            $errorRate = (float) ($errors[$endpoint] ?? 0.0);
            $results[$endpoint] = $requestRate > 0 ? $errorRate / $requestRate : 0.0;
        }

        return $results;
    }

    public function getLatencyPercentiles(float $quantile = 0.95): array
    {
        $query = sprintf(
            'histogram_quantile(%.2F, sum by (le, path) (rate(app_http_request_duration_seconds_bucket[5m])))',
            $quantile
        );

        return $this->vectorToEndpointMap($this->query($query), 'path');
    }

    public function getErrorCategoryCounters(): array
    {
        $results = [];

        foreach ($this->query('sum by (path, error_category) (increase(app_http_errors_total[5m]))') as $row) {
            $metric = $row['metric'] ?? [];
            $endpoint = $this->normalizeEndpoint((string) ($metric['path'] ?? 'unknown'));
            $category = (string) ($metric['error_category'] ?? 'UNKNOWN_ERROR');
            $results[$endpoint][$category] = $this->extractValue($row['value'] ?? []);
        }

        foreach ($this->knownEndpoints() as $endpoint) {
            $results[$endpoint] = $results[$endpoint] ?? [];
        }

        return $results;
    }

    public function snapshot(): array
    {
        $requestRates = $this->getRequestRateByEndpoint();
        $errorRates = $this->getErrorRateByEndpoint();
        $latencyP95 = $this->getLatencyPercentiles(0.95);
        $latencyP50 = $this->getLatencyPercentiles(0.50);
        $errorCategories = $this->getErrorCategoryCounters();

        $snapshot = [];
        foreach ($this->knownEndpoints() as $endpoint) {
            $snapshot[$endpoint] = [
                'request_rate' => (float) ($requestRates[$endpoint] ?? 0.0),
                'error_rate' => (float) ($errorRates[$endpoint] ?? 0.0),
                'latency_p95' => (float) ($latencyP95[$endpoint] ?? 0.0),
                'latency_p50' => (float) ($latencyP50[$endpoint] ?? 0.0),
                'error_categories' => $errorCategories[$endpoint] ?? [],
            ];
        }

        return $snapshot;
    }

    public function knownEndpoints(): array
    {
        return [
            '/api/normal',
            '/api/slow',
            '/api/db',
            '/api/error',
            '/api/validate',
        ];
    }

    private function vectorToEndpointMap(array $rows, string $label): array
    {
        $results = [];

        foreach ($rows as $row) {
            $endpoint = $this->normalizeEndpoint((string) ($row['metric'][$label] ?? 'unknown'));
            $results[$endpoint] = $this->extractValue($row['value'] ?? []);
        }

        foreach ($this->knownEndpoints() as $endpoint) {
            $results[$endpoint] = (float) ($results[$endpoint] ?? 0.0);
        }

        return $results;
    }

    private function extractValue(array $value): float
    {
        return isset($value[1]) ? (float) $value[1] : 0.0;
    }

    private function normalizeEndpoint(string $path): string
    {
        if ($path === '') {
            return 'unknown';
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }
}
