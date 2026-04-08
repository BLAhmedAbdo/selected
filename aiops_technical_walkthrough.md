# AIOps Detection & Observability System: Technical Walkthrough

Good day, everyone. Today, I am presenting the comprehensive architecture and implementation details of the AIOps platform I developed. The goal of this system is to evolve typical application monitoring from a passive, reactive posture into a proactive, intelligent, and self-analyzing AIOps engine. 

I built this project across three logical milestones (or "Labs"), progressing from deep observability to rule-based incident generation, and peaking at machine-learning-driven anomaly detection. 

---

## 1. Architecture Overview

### End-to-End System Flow
I designed the architecture to handle request telemetry, transform it into metrics, and use both deterministic rules and statistical models to find anomalies.

1. **Request Intake & Telemetry**: Traffic hits the Laravel API. My custom `TelemetryMiddleware` intercepts each request, attaches a unique Correlation ID, computes the exact latency in milliseconds, and traps performance outliers (e.g., hidden timeouts).
2. **Structured Logging**: Telemetry is serialized as a strict JSON schema and appended to `storage/logs/aiops.log`.
3. **Metrics Aggregation**: The `PrometheusService` dynamically parses these logs on-the-fly and populates a Prometheus `CollectorRegistry`. This transforms flat logs into multi-dimensional time-series data (counters and histograms).
4. **Active Detection Engine**: A specialized background daemon, `php artisan aiops:detect`, continuously polls Prometheus using PromQL, maintaining a rolling statistical baseline of the system's "normal" state. It evaluates live metrics against complex rules and fires anomalies.
5. **Correlation & Alerting**: The `IncidentCorrelator` merges concurrent anomaly signals into distinct, actionable Incidents, removing noise and grouping related failures.
6. **Machine Learning Pipeline**: Separately, the `ml_anomaly_detector.py` script pulls historical logs, performs feature engineering over 30-second sliding windows, and trains an `IsolationForest` model to detect subtle performance drifts invisible to static thresholds.

---

## 2. Lab 1 – Observability Core

The foundation of any AIOps system is data quality. I explicitly chose not to depend on black-box APMs; I implemented full observability into the HTTP stack itself.

### A) API Endpoints Development
I implemented several simulated endpoints representing different types of real-world backend behavior, including variable latency, DB failures, validation checks, and total outages.

```php
// routes/api.php
Route::get('/normal', function () {
    return response()->json(['status' => 'ok', 'message' => 'Normal response']);
});

Route::get('/slow', function (Request $request) {
    if ($request->query('hard') == 1) {
        sleep(rand(5, 7)); // Hard delay
    } else {
        sleep(2); // Normal slow
    }
    return response()->json(['status' => 'ok', 'message' => 'Slow response']);
});

Route::get('/db', function (Request $request) {
    if ($request->query('fail') == 1) {
        DB::select('SELECT * FROM table_that_does_not_exist');
    }
    $users = DB::select('SELECT * FROM users LIMIT 1');
    return response()->json(['status' => 'ok', 'data_count' => count($users)]);
});
```

### B) Telemetry Middleware
This is arguably the most critical component of the monitoring stack. My `TelemetryMiddleware` wraps every cycle.

```php
// app/Http/Middleware/TelemetryMiddleware.php
public function handle(Request $request, Closure $next): Response
{
    $startTime = microtime(true);
    // Standardize or generate unique X-Request-Id for distributed tracing
    $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();
    $request->attributes->set('request_id', $requestId);

    $response = $next($request);

    // Compute extremely precise latency in ms
    $latencyMs = round(((microtime(true) - $startTime) * 1000), 2);
    $response->headers->set('X-Request-Id', $requestId);

    $errorCategory = null;
    // Hard trap: Latency > 4s is an error, even if HTTP status is 200 OK
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
        'payload_size_bytes' => strlen($request->getContent()),
        'error_category' => $errorCategory,
        'severity' => ($response->getStatusCode() >= 400 || $latencyMs > 4000) ? 'error' : 'info',
    ]));

    return $response;
}
```
**Key Highlights**: 
- **Hidden Errors Detected**: Notice the `TIMEOUT_ERROR` classification. If a 3rd party API takes 5 seconds but returns a 200 OK, standard metrics miss it. My middleware explicitly tags it as a severe anomaly.
- **Payload metrics**: Tracks `payload_size_bytes` internally.

### C) Exception Handling
I remapped Laravel's exception handler directly into my categorized schema. 

```php
// bootstrap/app.php
$exceptions->render(function (\Illuminate\Database\QueryException $e) {
    return response()->json([
        'error_category' => 'DATABASE_ERROR',
        'message' => $e->getMessage(),
    ], 500);
});

$exceptions->render(function (\Illuminate\Validation\ValidationException $e) {
    return response()->json([
        'error_category' => 'VALIDATION_ERROR',
        'message' => $e->getMessage(),
    ], 422);
});
```
This forces all app crashes to return consistent `error_category` attributes, which Prometheus later scrapes as labels.

### D) Structured Logging
By standardizing on a strict, flat JSON format pushed to `aiops.log`, I completely bypassed log-parsing headaches. This guarantees data consistency and makes the subsequent machine-learning phase mathematically viable without complex text-parsing rules.

### E) Prometheus Metrics Injection
I implemented the `PrometheusService` which transforms the physical log file into in-memory Prometheus metric families.

```php
// app/Services/PrometheusService.php
$requestCounter = $this->registry->getOrRegisterCounter(
    'app', 'http_requests_total', 'Total number of HTTP requests', ['method', 'path', 'status']
);
$histogram = $this->registry->getOrRegisterHistogram(
    'app', 'http_request_duration_seconds', 'HTTP request duration in seconds', ['method', 'path'],
    [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
);

// Snippet of metric ingestion
if ($errorCategory !== null) {
    $errorCounter->inc([$method, $path, $errorCategory]);
}
$histogram->observe($latencyMs / 1000, [$method, $path]);
```
**Design Choice**: I purposefully limited Prometheus labels to `method`, `path`, `status`, and `error_category` to intentionally avoid **high cardinality explosions** (thus I didn't include `client_ip` or `request_id` in metrics, strictly keeping them in the logs).

### G & H) Traffic Simulation & Dataset Export
To thoroughly test the engine, I wrote Python scripts that simulate massive load:

```python
# lab3/generate_mock_logs.py
if is_anomaly:
    weights = [30, 10, 5, 45, 5, 5] # Huge spike in errors
else:
    weights = [70, 15, 5, 5, 3, 2] # Normal distribution
```
The output, `logs.json`, gives me 10+ minutes of perfect, ML-ready telemetry combining normal operating parameters with massive orchestrated DB and latency spikes.

---

## 3. Lab 2 – The Detection Engine

With telemetry live, I needed an active agent. I wrote an AIOps CLI daemon that continuously assesses system health.

### A) The Continuous Command (`aiops:detect`)
This daemon runs an infinite `while(true)` loop, executing every 25 seconds.

```php
// app/Console/Commands/AiopsDetectCommand.php
public function handle(...) {
    while (true) {
        $snapshot = $prometheusClient->snapshot();
        $baselines = $baselineService->getBaselines();
        $signals = $anomalyDetector->detect($snapshot, $baselines);
        $incidents = $incidentCorrelator->correlate($signals, $snapshot, $baselines);
        
        foreach ($incidents as $incident) {
            $incidentRepository->record($incident);
            $alertService->emit($incident, ...);
        }
        $baselineService->updateWithSnapshot($snapshot);
        sleep(max(20, min(30, $intervalSeconds)));
    }
}
```
This is pure autonomy. The system loops, observes metrics, reads historical baselines, projects anomalies, correlates them sideways, and persists updates.

### B) The Prometheus Client
I implemented raw PromQL query execution utilizing Laravel's HTTP client.
```php
// app/Services/PrometheusClient.php
public function getRequestRateByEndpoint(): array {
    return $this->vectorToEndpointMap(
        $this->query('sum by (path) (rate(app_http_requests_total[2m]))'), 'path'
    );
}

// Complex PromQL for P95 latency distributions
public function getLatencyPercentiles(float $quantile = 0.95): array {
    $queryString = sprintf(
        'histogram_quantile(%.2F, sum by (le, path) (rate(app_http_request_duration_seconds_bucket[5m])))',
        $quantile
    );
    return $this->vectorToEndpointMap($this->query($queryString), 'path');
}
```

### C) Dynamic Baseline Modeling
Static thresholds fail in modern cloud systems. A system under 10k requests/sec acts differently than one under 10 requests/sec. Rather than hardcoding rules (e.g., "Alert if latency > 200ms"), the `BaselineService` calculates rolling averages for `latency_p95`, `error_rate`, and `request_rate` from the last 30 snapshots internally stored in `storage/aiops/baselines.json`.

### D) Anomaly Detection Rules
The system then flags individual statistical deviations.

```php
// app/Services/AnomalyDetector.php
if ($warm && $baselineLatency > 0 && $latency > $baselineLatency * 3) {
    $signals[] = $this->signal('LATENCY_ANOMALY', $endpoint, 'high', ...);
}

// Alert dynamically if traffic suddenly doubles
if ($warm && $baselineRequestRate > 0 && $requestRate > $baselineRequestRate * 2) {
    $signals[] = $this->signal('TRAFFIC_ANOMALY', $endpoint, 'medium', ...);
}
```

### E) Event Correlation
Individual signals are noisy. If a database goes down, latency will spike *AND* errors will spike across 4 endpoints. I developed the `IncidentCorrelator` to merge signals structurally, mimicking what a human Site Reliability Engineer would do.

```php
// app/Services/IncidentCorrelator.php
if (in_array('LATENCY_ANOMALY', $types, true) && in_array('ERROR_RATE_ANOMALY', $types, true)) {
    $incidents[] = $this->buildIncident('SERVICE_DEGRADATION', $endpointSignals, $snapshot, $baselines, 'high', 'Correlated latency and error degradation detected');
}
```
> [!TIP]
> The correlator also uses a `sha1()` hashing function to fingerprint incidents across regions and endpoints, ensuring that I deduplicate alert noise heavily before firing notifications.

---

## 4. Lab 3 – Machine Learning Phase

While the rule-based engine is excellent for known failure domains, it misses gradual regressions and multi-dimensional behavioral shifts. I added a layer of Machine Learning using Python's `scikit-learn` stack.

### A) Feature Engineering
I aggregated raw JSON logs into `30S` (30-second) time windows using Pandas. I mathematically derived features like:
- `avg_latency` and `max_latency`
- `latency_std` (deviation inside the window)
- `error_rate` 
- `endpoint_frequency` (proportion of traffic hitting this endpoint compared to global traffic)

```python
# lab3/ml_anomaly_detector.py
avg_latency = resampled['latency_ms'].mean().fillna(0)
request_rate = resampled['status_code'].count()
# Compute specific error ratios inside the 30s window without losing sync
errors_per_window = errors.resample(f'{window_seconds}S')['status_code'].count()
error_rate = (errors_per_window / request_rate).fillna(0)
```

### B) Unsupervised Model Training
I utilized the **Isolation Forest** model mapping. Why use Isolation Forest? Because in production IT environments, we predominantly have "normal" data; anomalies are extreme rarities. I trained the model on a purely "normal" window (the first 7 minutes of the dataset), letting it define the boundary of acceptable health.

```python
# Train Isolation Forest on normal data
model = IsolationForest(n_estimators=100, contamination=0.01, random_state=42)
model.fit(normal_data[model_features])

# Predict against the entire timeline
predictions = model.predict(features_df[model_features])
scores = model.score_samples(features_df[model_features])

# Output -1 is an anomaly
features_df['is_anomaly'] = np.where(predictions == -1, True, False)
features_df['anomaly_score'] = -scores 
```

### C) Visualization Output
The output highlights exactly what the ML deemed an outlier.

```python
plt.plot(features_df['timestamp'], features_df['error_rate'], alpha=0.5, label='Error Rate')
anomalies = features_df[features_df['is_anomaly']]
plt.scatter(anomalies['timestamp'], anomalies['error_rate'], color='red', label='Anomalies')
```

---

## 5. End-to-End Incident Scenario

**The Event:** A developer commits a bad query affecting a specific endpoint, causing the processing time to skyrocket. A user hits `/api/slow?hard=1`.

1. **Intake**: The router hits the closure. The closure simulates 5–7 seconds of sleep.
2. **Middleware Evaluation**: The response returns `200 OK`. A traditional system logs a successful request. My `TelemetryMiddleware` compares `$latencyMs` against the `4000ms` hard threshold. 
3. **Capture**: The middleware forcefully overwrites the `error_category` to `TIMEOUT_ERROR` and saves it to JSON.
4. **Metrics Generation**: `PrometheusService` parses the log, incrementing the `http_errors_total` counter with the `TIMEOUT_ERROR` category, and pushing the huge latency observation into the long buckets of the `http_request_duration_seconds` histogram.
5. **Detection Loop**: 15 seconds later, `php artisan aiops:detect` queries the P95 latency. The P95 for `/api/slow` is now registering at `6.5s`.
6. **Rule Breach**: `AnomalyDetector` compares `6.5s` against the `BaselineService`'s expected `2.1s`. It raises a `LATENCY_ANOMALY` signal.
7. **Correlation**: `IncidentCorrelator` maps the system array, generating a structured `LATENCY_SPIKE` incident explicitly targeting the `/api/slow` endpoint, generates a unique deduplication fingerprint, and fires the final JSON notification to the `AlertService`.
8. **ML Confirmation**: Later, the offline `ml_anomaly_detector.py` scans the window, noticing massive spikes in `max_latency` and flags the time-slice as `is_anomaly: True` mathematically correlating the incident.

---

## 6. Engineering Justifications & Trade-offs

I want to highlight a few critical architectural choices I made:

1. **Why Structured Logging to Prometheus?** 
   Most systems use a heavy sidecar to export metrics. By using dense JSON flat-file logs in the application layer and a parser, I maintain an immutable paper trail of every request (`logs.json` dataset), which is strictly required for the Python Machine Learning phase. I decoupled APM from the execution logic perfectly.

2. **Why build the Rules Engine First?**
   Machine Learning is a statistical buzzword, but in IT operations, precision matters. When a database goes down, we don't need a predictive neural net to tell us we failed—we need an immediate, deterministic trigger. Rule-based detection provides 100% confidence.

3. **Why attach Machine Learning then?**
   While deterministic rules handle total outages and surges, they miss drift. E.g. Latency creeping from 100ms to 120ms to 150ms over a week won't trigger a `3x baseline` rule, but an Isolation Forest perceives the distribution shift and flags it perfectly.

By layering High-Cardinality Telemetry -> Real-time Statistics -> Rolling Baseline Rules -> Unsupervised ML Isolation, I have produced a deeply mature, SRE-grade AIOps engine directly into the application space.

Thank you.
