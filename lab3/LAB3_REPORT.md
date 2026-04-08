# Lab Work 3: ML Anomaly Detection Engineering Report

## 1. Chosen Features and Feature Engineering
For the anomaly detection task, the raw telemetry logs (`logs.json`) were aggregated into 30-second time windows. We engineered operational features that capture both the latency characteristics and the traffic load of each endpoint. The chosen features are:
- **`avg_latency`**: The mean response time within the window. Captures overall degradation.
- **`max_latency`**: The peak response time in the window. Isolates extreme single-request slowdowns.
- **`latency_std`**: The standard deviation of latency. Highlights erratic response behavior.
- **`request_rate`**: The total count of requests received over the window. Crucial for detecting traffic anomalies or surges.
- **`errors_per_window`**: Number of requests with `status_code >= 400` or an explicit `error_category` per window.
- **`error_rate`**: The ratio of errors to total requests per window. Useful for normalizing error occurrences.
- **`endpoint_frequency`**: The proportion of traffic assigned to the current endpoint relative to the entire system's traffic for that time window. Highlights abnormal shifts in traffic distribution.

These features effectively summarize raw log events into statistical windows that ML models can utilize for density estimation to find unusual patterns.

## 2. Model Selection
We utilized the **Isolation Forest** model to detect anomalies. The rationale behind selecting Isolation Forest includes:
1. **Unsupervised Learning**: Isolation Forest excels in environments where labeled anomalies are rare or non-existent prior to training.
2. **Computational Efficiency**: It isolates observations by randomly selecting a feature and a split value. Anomalies have noticeably shorter path lengths in the trees, making the algorithm incredibly fast, even with large telemetry datasets.
3. **Robustness to High Dimensions**: Though we only have 7 features, Isolation Forest scales easily and handles outliers naturally.

The model was strictly trained on the **normal behavior period** (first 7 minutes of the traffic generator logic) by restricting the `timestamp` bounds during the `fit()` step. The remaining duration (including the 2-minute anomaly spike) was predicted using the frozen model.

## 3. Anomaly Detection Performance
The trained Isolation Forest model effectively identified the simulated anomaly windows from Lab 1. 

- **Error Spikes Detection**: Because `error_rate` and `errors_per_window` were included in the model features, the sudden spike (e.g., the 35%-50% expected in the anomaly window) resulted in extremely low probability density scores, allowing the Isolation Forest to instantly flag `is_anomaly = True`.
- **Latency Spikes Detection**: The `avg_latency` and `latency_std` features similarly caught the latency injection (`/api/slow?hard=1` producing 4000ms+ timeouts) during the anomaly window.
- **Anomaly Scores**: The `anomaly_score` correctly reflects the confidence of the outlier detection; periods representing the simulated traffic storm showed drastically higher anomaly scores compared to baseline measurements.

Visual review of `latency_timeline.png` and `error_rate_timeline.png` clearly demonstrates that the flagged points (highlighted in red) correctly map to the injected 2-minute anomaly window, proving the model is highly sensitive to real operational anomalies without raising excessive false positives during normal traffic.
