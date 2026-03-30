<?php
use App\Services\PrometheusService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/metrics', function (PrometheusService $prometheus) {
    return response($prometheus->render(), 200)
        ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
});