<?php

use App\Services\PrometheusService;
use Illuminate\Support\Facades\Route;
use App\Services\PrometheusClient;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/metrics', function (PrometheusService $prometheus) {
    return response($prometheus->render(), 200)
    ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
});

Route::get('/test-prometheus', function (PrometheusClient $client) {

    $requests = $client->query('sum(rate(http_requests_total[1m])) by (path, method, status)');

    return response()->json($requests);
});