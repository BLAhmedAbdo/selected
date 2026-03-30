# Lab Work 2 - AIOps Detection Engine

## Overview
This implementation upgrades the Lab 1 observability project into an active detection engine. The detector runs continuously, polls Prometheus every 25 seconds, computes per-endpoint baselines from observed metrics, detects multi-signal anomalies, correlates them into incidents, stores structured incident records, and emits deduplicated alerts.

## Implemented Components
- `php artisan aiops:detect` continuous detector command
- `App\Services\PrometheusClient` for Prometheus API integration
- `App\Services\BaselineService` for dynamic baseline modeling
- `App\Services\AnomalyDetector` for rule-based multi-signal detection
- `App\Services\IncidentCorrelator` for higher-level incident generation
- `App\Services\IncidentRepository` for `incidents.json` and active incident state
- `App\Services\AlertService` for alert emission and suppression

## Baseline Design
Baselines are calculated separately for:
- `/api/normal`
- `/api/slow`
- `/api/db`
- `/api/error`
- `/api/validate`

The system stores rolling observations in `storage/aiops/baselines.json`. For each endpoint it records:
- `request_rate`
- `error_rate`
- `latency_p95`

A rolling window of the most recent 30 samples is preserved. The baseline values are computed from the mean of these observed samples, so baseline values are derived from real telemetry rather than hardcoded constants. A warm-up threshold of 5 samples is used before strong relative comparisons are applied.

## Prometheus Queries
The detector queries:
- request rate per endpoint: `sum by (path) (rate(app_http_requests_total[2m]))`
- error rate source series: `sum by (path) (rate(app_http_errors_total[2m]))`
- latency percentiles: `histogram_quantile(0.95, sum by (le, path) (rate(app_http_request_duration_seconds_bucket[5m])))`
- error category counters: `sum by (path, error_category) (increase(app_http_errors_total[5m]))`

## Anomaly Rules
The detection logic evaluates multiple signals per endpoint.

### Latency anomaly
Triggered when:
- p95 latency > 3 x baseline latency

### Error rate anomaly
Triggered when:
- error rate > 10%
- or error rate > 3 x baseline error rate

### Traffic anomaly
Triggered when:
- request rate > 2 x baseline request rate

### Endpoint-specific anomaly
Triggered when:
- endpoint error rate >= 90%

## Event Correlation Strategy
Signals are grouped and correlated so that one abnormal situation creates one higher-level incident instead of many isolated alerts.

### Incident types
- `SERVICE_DEGRADATION`: latency and error signals on the same endpoint
- `ERROR_STORM`: multiple endpoints experiencing major error anomalies
- `LATENCY_SPIKE`: isolated latency issue
- `LOCALIZED_ENDPOINT_FAILURE`: failure concentrated on one endpoint
- `TRAFFIC_SURGE`: endpoint-level or cross-endpoint traffic anomaly

Each incident includes:
- `incident_id`
- `incident_type`
- `severity`
- `status`
- `detected_at`
- `affected_service`
- `affected_endpoints`
- `triggering_signals`
- `baseline_values`
- `observed_values`
- `summary`

## Incident Persistence
Structured incidents are written to:
- `storage/aiops/incidents.json`

The active incident state used for suppression and lifecycle tracking is stored in:
- `storage/aiops/active_incidents.json`

## Alerting and Deduplication
When a new incident is created the detector emits:
- console alert
- JSON alert record in `storage/aiops/alerts.json`

Repeated alerts for the same active incident are suppressed using a stable fingerprint built from:
- incident type
- sorted affected endpoints

This guarantees that repeated detector cycles do not keep re-alerting for the same ongoing incident.

## Console Checkpoint
Every detector cycle prints the current endpoint metrics to the console:
- request rate
- error rate
- p95 latency
- error category counters

## How to Run
1. Start Laravel and expose `/metrics`
2. Start Prometheus with `prometheus.yml`
3. Generate traffic and anomaly windows from Lab 1
4. Run:

```bash
php artisan aiops:detect
```

The command is intentionally long-running and sleeps 25 seconds between evaluation cycles.
