# AIOps Observability + Detection Engine

This Laravel project now includes both Lab 1 observability telemetry and Lab 2 AIOps detection.

## Lab 2 quick start

1. Run the Laravel app so `/metrics` is available.
2. Run Prometheus using `prometheus.yml`.
3. Generate API traffic, including the anomaly window from Lab 1.
4. Start the detector:

```bash
php artisan aiops:detect
```

## Output files
- `storage/aiops/baselines.json`
- `storage/aiops/incidents.json`
- `storage/aiops/active_incidents.json`
- `storage/aiops/alerts.json`

## Main implementation files
- `app/Console/Commands/AiopsDetectCommand.php`
- `app/Services/PrometheusClient.php`
- `app/Services/BaselineService.php`
- `app/Services/AnomalyDetector.php`
- `app/Services/IncidentCorrelator.php`
- `app/Services/IncidentRepository.php`
- `app/Services/AlertService.php`
- `REPORT.md`



# AIOps Detection Engine - Lab Work 2

## Overview

In this lab, I implemented an AIOps Detection Engine on top of a Laravel application.

The system continuously collects metrics from Prometheus, builds baselines, detects anomalies, correlates events, and generates structured incidents with alerting.

---

## How to Run

### 1. Install dependencies

```bash
composer install
php artisan key:generate
```

### 2. Start Laravel server

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

### 3. Start Prometheus (Docker)

```bash
docker run -d -p 9090:9090 -v "%cd%/prometheus.yml:/etc/prometheus/prometheus.yml" prom/prometheus
```

### 4. Run Detection Engine

```bash
php artisan aiops:detect
```

---

## System Components

### 1. Prometheus Integration

* Fetches metrics from:

```
http://localhost:9090/api/v1/query
```

* Metrics include:

  * request rate
  * error rate
  * latency (P95)
  * error categories

---

### 2. Baseline Modeling

* Baselines are calculated per endpoint:

  * /api/normal
  * /api/slow
  * /api/db
  * /api/error
  * /api/validate

* Includes:

  * average latency
  * request rate
  * error rate

* Baselines are dynamic and derived from real data.

---

### 3. Anomaly Detection Rules

The system detects anomalies using:

* Latency anomaly:

  * latency > 3 × baseline

* Error rate anomaly:

  * error rate > 10%

* Traffic anomaly:

  * traffic > 2 × baseline

* Endpoint failure:

  * high error rate on a single endpoint

---

### 4. Event Correlation

Multiple abnormal signals are grouped into a single incident.

Incident types include:

* ERROR_STORM
* SERVICE_DEGRADATION
* LOCALIZED_ENDPOINT_FAILURE
* TRAFFIC_SURGE

This reduces alert noise.

---

### 5. Incident Generation

Incidents are stored in:

```
storage/aiops/incidents.json
```

Each incident contains:

* incident_id
* incident_type
* severity
* status
* detected_at
* affected_endpoints
* triggering_signals
* baseline_values
* observed_values
* summary

---

### 6. Alerting System

Alerts are printed in console:

```
[ALERT] {...}
```

Each alert includes:

* incident_id
* incident_type
* severity
* timestamp
* summary

---

### 7. Alert Suppression

Duplicate alerts are prevented using fingerprinting.

Example:

```
[ALERT SUPPRESSED] INC-XXXX ERROR_STORM
```

---

## Testing

### Generated Traffic

```bash
curl http://127.0.0.1:8000/api/normal
curl http://127.0.0.1:8000/api/slow
curl http://127.0.0.1:8000/api/db
curl http://127.0.0.1:8000/api/error
curl -X POST http://127.0.0.1:8000/api/validate
```

### Observed Behavior

* System detected:

  * latency anomalies
  * error spikes
  * traffic surges

* Console output:

```
Detected 4 abnormal signals and 3 correlated incidents
```

* Alerts generated:

```
[ALERT] ERROR_STORM
```

* Duplicate alerts suppressed:

```
[ALERT SUPPRESSED]
```

* When traffic stopped:

```
No anomalies detected in this cycle
```

---

## Conclusion

The system successfully:

* Integrates with Prometheus
* Builds dynamic baselines
* Detects anomalies using multiple signals
* Correlates events into incidents
* Generates structured incidents
* Sends alerts with suppression

This simulates a real-world AIOps monitoring system.
