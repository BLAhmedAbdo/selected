<?php

namespace App\Http\Middleware;

use App\Services\PrometheusService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TelemetryMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);

        $latencyMs = (microtime(true) - $startTime) * 1000;
        $latencyMs = round($latencyMs, 2);

        $request->attributes->set('latency_ms', $latencyMs);

        $response->headers->set('X-Request-Id', $requestId);

        $errorCategory = null;

        if ($latencyMs > 4000) {
            $errorCategory = 'TIMEOUT_ERROR';
        } elseif ($response->getStatusCode() >= 400) {
            $errorCategory = 'SYSTEM_ERROR';
        }

        Log::channel('aiops')->info(json_encode([
            'timestamp' => now()->toIso8601String(),
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'latency_ms' => $latencyMs,
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query' => $request->getQueryString(),
            'payload_size_bytes' => strlen($request->getContent()),
            'response_size_bytes' => strlen($response->getContent()),
            'route_name' => optional($request->route())->getName(),
            'error_category' => $errorCategory,
            'severity' => ($response->getStatusCode() >= 400 || $latencyMs > 4000) ? 'error' : 'info',
            'build_version' => env('BUILD_VERSION'),
            'host' => gethostname(),
        ]));


        return $response;
    }
}