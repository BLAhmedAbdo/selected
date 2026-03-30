<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PrometheusClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.prometheus.base_url', 'http://localhost:9090');
    }

    public function query(string $query): array
    {
        $response = Http::get($this->baseUrl . '/api/v1/query', [
            'query' => $query,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Prometheus query failed: ' . $response->body());
        }

        $json = $response->json();

        return $json['data']['result'] ?? [];
    }
}