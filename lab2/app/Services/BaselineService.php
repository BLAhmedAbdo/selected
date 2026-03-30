<?php

namespace App\Services;

class BaselineService
{
    private string $storagePath;

    public function __construct()
    {
        $this->storagePath = storage_path('aiops/baselines.json');
        $this->ensureStorage();
    }

    public function getBaselines(): array
    {
        $data = $this->load();
        $baselines = [];

        foreach ($data['endpoints'] as $endpoint => $payload) {
            $samples = $payload['samples'] ?? [];
            $baselines[$endpoint] = [
                'average_latency' => $this->average($samples, 'latency_p95'),
                'request_rate' => $this->average($samples, 'request_rate'),
                'error_rate' => $this->average($samples, 'error_rate'),
                'sample_count' => count($samples),
                'warm' => count($samples) >= 5,
            ];
        }

        return $baselines;
    }

    public function updateWithSnapshot(array $snapshot): array
    {
        $data = $this->load();

        foreach ($snapshot as $endpoint => $metrics) {
            $data['endpoints'][$endpoint] = $data['endpoints'][$endpoint] ?? ['samples' => []];
            $samples = $data['endpoints'][$endpoint]['samples'];
            $samples[] = [
                'captured_at' => now()->toIso8601String(),
                'request_rate' => round((float) ($metrics['request_rate'] ?? 0.0), 6),
                'error_rate' => round((float) ($metrics['error_rate'] ?? 0.0), 6),
                'latency_p95' => round((float) ($metrics['latency_p95'] ?? 0.0), 6),
            ];
            $data['endpoints'][$endpoint]['samples'] = array_slice($samples, -30);
        }

        $data['updated_at'] = now()->toIso8601String();
        $this->save($data);

        return $this->getBaselines();
    }

    private function load(): array
    {
        if (! file_exists($this->storagePath)) {
            return $this->seed();
        }

        $decoded = json_decode((string) file_get_contents($this->storagePath), true);

        if (! is_array($decoded) || ! isset($decoded['endpoints'])) {
            return $this->seed();
        }

        return $decoded;
    }

    private function save(array $data): void
    {
        file_put_contents($this->storagePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function average(array $samples, string $key): float
    {
        if ($samples === []) {
            return 0.0;
        }

        $values = array_map(static fn (array $sample): float => (float) ($sample[$key] ?? 0.0), $samples);

        return round(array_sum($values) / max(count($values), 1), 6);
    }

    private function ensureStorage(): void
    {
        if (! is_dir(dirname($this->storagePath))) {
            mkdir(dirname($this->storagePath), 0777, true);
        }

        if (! file_exists($this->storagePath)) {
            $this->save($this->seed());
        }
    }

    private function seed(): array
    {
        $endpoints = [];
        foreach ((new PrometheusClient())->knownEndpoints() as $endpoint) {
            $endpoints[$endpoint] = ['samples' => []];
        }

        return [
            'updated_at' => now()->toIso8601String(),
            'endpoints' => $endpoints,
        ];
    }
}
