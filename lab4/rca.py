import json
import os
import datetime
from collections import defaultdict

try:
    import pandas as pd
    import matplotlib.pyplot as plt
    import matplotlib.dates as mdates
except ImportError:
    import subprocess
    import sys
    print("Installing required packages...")
    subprocess.check_call([sys.executable, "-m", "pip", "install", "pandas", "matplotlib", "seaborn"])
    import pandas as pd
    import matplotlib.pyplot as plt
    import matplotlib.dates as mdates

def run_rca():
    lab4_dir = os.path.dirname(os.path.abspath(__file__))
    os.makedirs(lab4_dir, exist_ok=True)
    project_root = os.path.dirname(lab4_dir)
    
    incidents_path = os.path.join(project_root, 'storage', 'aiops', 'incidents.json')
    logs_path = os.path.join(project_root, 'lab3', 'logs.json')
    
    if not os.path.exists(incidents_path):
        print(f"Error: {incidents_path} not found.")
        return
    if not os.path.exists(logs_path):
        print(f"Error: {logs_path} not found.")
        return
        
    with open(incidents_path, 'r') as f:
        incidents = json.load(f)
        
    if not incidents:
        print("No incidents found.")
        return
        
    # Pick the most critical or recent incident
    # Sort by severity and timestamp
    def sev_score(s):
        return {"low": 1, "medium": 2, "high": 3, "critical": 4}.get(s.lower(), 0)
        
    incidents.sort(key=lambda x: (sev_score(x.get('severity', 'none')), x.get('detected_at')), reverse=True)
    incident = incidents[0]
    
    # Load logs
    with open(logs_path, 'r') as f:
        logs = json.load(f)
        
    df = pd.DataFrame(logs)
    df['timestamp'] = pd.to_datetime(df['timestamp'], utc=True)
    df = df.sort_values('timestamp')
    
    incident_time = pd.to_datetime(incident['detected_at'], utc=True)
    # Anomaly window: 2 minutes before detection to 1 minute after
    anomaly_start = incident_time - pd.Timedelta(minutes=3)
    anomaly_end = incident_time + pd.Timedelta(minutes=1)
    
    # Baseline window: 10 minutes before anomaly
    baseline_start = anomaly_start - pd.Timedelta(minutes=10)
    baseline_end = anomaly_start
    
    baseline_df = df[(df['timestamp'] >= baseline_start) & (df['timestamp'] < baseline_end)]
    anomaly_df = df[(df['timestamp'] >= anomaly_start) & (df['timestamp'] <= anomaly_end)]
    
    if baseline_df.empty or anomaly_df.empty:
        # Fallback to absolute split based on existing mock data logic (~ min 0 to 7 normal, 7 to 9 anomaly)
        print("Warning: time window mismatch. Using global heuristic window.")
        anomaly_df = df[df.index >= int(len(df)*0.7)]
        baseline_df = df[df.index < int(len(df)*0.7)]
        anomaly_start = anomaly_df['timestamp'].iloc[0]
        anomaly_end = anomaly_df['timestamp'].iloc[-1]
    
    # Signal Analysis
    metrics_baseline = baseline_df.groupby('path').agg(
        avg_latency=('latency_ms', 'mean'),
        request_count=('timestamp', 'count'),
        error_count=('status_code', lambda x: (x >= 400).sum())
    )
    
    metrics_anomaly = anomaly_df.groupby('path').agg(
        avg_latency=('latency_ms', 'mean'),
        request_count=('timestamp', 'count'),
        error_count=('status_code', lambda x: (x >= 400).sum())
    )
    
    # Compute error rates
    metrics_baseline['error_rate'] = metrics_baseline['error_count'] / metrics_baseline['request_count'].replace(0, 1)
    metrics_anomaly['error_rate'] = metrics_anomaly['error_count'] / metrics_anomaly['request_count'].replace(0, 1)
    
    # Calculate Deviation Scores
    diff = pd.DataFrame(index=metrics_anomaly.index)
    diff['latency_diff'] = metrics_anomaly['avg_latency'].sub(metrics_baseline['avg_latency'], fill_value=0)
    diff['error_rate_diff'] = metrics_anomaly['error_rate'].sub(metrics_baseline['error_rate'], fill_value=0)
    diff['volume_ratio'] = metrics_anomaly['request_count'].div(metrics_baseline['request_count'].replace(0, 1), fill_value=1)
    
    # Determine the root cause endpoint by scoring
    # Normalize diffs to find max contributor
    diff['score'] = (diff['latency_diff'] / max(1, diff['latency_diff'].max())) + \
                    (diff['error_rate_diff'] / max(0.001, diff['error_rate_diff'].max())) + \
                    (diff['volume_ratio'] / max(1, diff['volume_ratio'].max()))
                    
    root_cause_endpoint = diff['score'].idxmax()
    primary_dev = diff.loc[root_cause_endpoint]
    
    # Identify Primary Signal
    if primary_dev['error_rate_diff'] > 0.1: # 10%
        primary_signal = "Error Rate Surge"
    elif primary_dev['latency_diff'] > 1000: # 1000ms
        primary_signal = "Latency Spike"
    elif primary_dev['volume_ratio'] > 2:
        primary_signal = "Traffic Burst"
    else:
        primary_signal = "Compound Degradation"

    # Analyze Error Categories
    cause_anomaly_df = anomaly_df[anomaly_df['path'] == root_cause_endpoint]
    error_categories = cause_anomaly_df['error_category'].dropna().value_counts().to_dict()
    
    # Timeline points
    timeline = [
        {"time": str(baseline_start), "event": "Normal baseline behavior observed."},
        {"time": str(anomaly_start), "event": f"Anomaly window begins. Activity shifts on {root_cause_endpoint}."},
        {"time": str(incident_time), "event": f"Detection engine triggers incident {incident['incident_id']}."},
        {"time": str(anomaly_end), "event": "Peak anomaly window ends (or telemetry truncated)."}
    ]
    
    rca_doc = {
        "incident_id": incident['incident_id'],
        "root_cause_endpoint": root_cause_endpoint,
        "primary_signal": primary_signal,
        "confidence_score": min(0.98, primary_dev['score'] / 3.0),
        "supporting_evidence": {
            "baseline": {
                "avg_latency_ms": metrics_baseline.loc[root_cause_endpoint, 'avg_latency'] if root_cause_endpoint in metrics_baseline.index else 0,
                "error_rate": metrics_baseline.loc[root_cause_endpoint, 'error_rate'] if root_cause_endpoint in metrics_baseline.index else 0
            },
            "anomaly": {
                "avg_latency_ms": metrics_anomaly.loc[root_cause_endpoint, 'avg_latency'] if root_cause_endpoint in metrics_anomaly.index else 0,
                "error_rate": metrics_anomaly.loc[root_cause_endpoint, 'error_rate'] if root_cause_endpoint in metrics_anomaly.index else 0
            }
        },
        "error_category_distribution": error_categories,
        "timeline_events": timeline,
        "recommended_action": f"Investigate recent deployments or upstream dependencies for '{root_cause_endpoint}'. Primary anomaly is {primary_signal}."
    }
    
    with open(os.path.join(lab4_dir, 'rca_report.json'), 'w') as f:
        json.dump(rca_doc, f, indent=4)
        
    print(f"RCA completed. Generated rca_report.json for {incident['incident_id']}")

    # Plot
    rca_df = df[df['path'] == root_cause_endpoint].copy()
    rca_df.set_index('timestamp', inplace=True)
    
    # Resample to 30s bins
    rca_resampled = rca_df.resample('30s').agg({
        'latency_ms': 'mean',
        'status_code': lambda x: (x >= 400).sum()
    })
    
    # Plotting
    fig, (ax1, ax2) = plt.subplots(2, 1, figsize=(10, 8), sharex=True)
    
    # Ax1: Latency
    ax1.plot(rca_resampled.index, rca_resampled['latency_ms'], color='orange', linewidth=2, label='Avg Latency (ms)')
    ax1.axvspan(anomaly_start, anomaly_end, color='red', alpha=0.2, label='Anomaly Window')
    ax1.set_ylabel('Latency (ms)')
    ax1.set_title(f"Timeline for {root_cause_endpoint}")
    ax1.legend(loc="upper left")
    
    # Ax2: Error Count
    ax2.bar(rca_resampled.index, rca_resampled['status_code'], width=0.0003, color='red', alpha=0.7, label='Errors Bins (30s)')
    ax2.axvspan(anomaly_start, anomaly_end, color='red', alpha=0.2, label='Anomaly Window')
    ax2.set_xlabel('Time')
    ax2.set_ylabel('Error Count')
    ax2.legend(loc="upper left")
    
    # Format x-axis
    ax2.xaxis.set_major_formatter(mdates.DateFormatter('%H:%M:%S'))
    plt.xticks(rotation=45)
    plt.tight_layout()
    
    plot_path = os.path.join(lab4_dir, 'incident_timeline.png')
    plt.savefig(plot_path)
    print(f"Generated visual report: {plot_path}")

    # Generate RCA Document
    md_content = f"""# Root Cause Analysis Report
**Incident ID**: {incident['incident_id']}
**Generated At**: {datetime.datetime.now().isoformat()}

## 1. Executive Summary
This document outlines the root cause analysis for incident `{incident['incident_id']}`.
The detection engine observed an anomaly characterized by **{incident.get('summary', 'system degradation')}**.
Through correlation of application logs and metrics, the most probable root cause has been isolated to the **`{root_cause_endpoint}`** endpoint, demonstrating a **{primary_signal}**.

- **Root Cause Endpoint**: `{root_cause_endpoint}`
- **Primary Signal**: {primary_signal}
- **Confidence Score**: {rca_doc['confidence_score']:.2f}

## 2. Signal Analysis
During the incident window ({anomaly_start} to {anomaly_end}), signal aggregates were compared to the preceding stable baseline.

| Metric | Baseline (Normal) | Incident Window | Status |
|--------|-------------------|-----------------|--------|
| **Average Latency** | {rca_doc['supporting_evidence']['baseline']['avg_latency_ms']:.2f} ms | {rca_doc['supporting_evidence']['anomaly']['avg_latency_ms']:.2f} ms | {'Degraded' if rca_doc['supporting_evidence']['anomaly']['avg_latency_ms'] > rca_doc['supporting_evidence']['baseline']['avg_latency_ms'] * 1.5 else 'Normal'} |
| **Error Rate** | {rca_doc['supporting_evidence']['baseline']['error_rate']*100:.2f}% | {rca_doc['supporting_evidence']['anomaly']['error_rate']*100:.2f}% | {'Spike' if rca_doc['supporting_evidence']['anomaly']['error_rate'] > 0.05 else 'Normal'} |

### Error Category Breakdown
During the anomaly window, the distribution of exact errors for `{root_cause_endpoint}` was:
"""
    for ecategory, count in error_categories.items():
        md_content += f"- **{ecategory}**: {count} occurrences\n"
    
    md_content += f"""

## 3. Incident Timeline
- **{baseline_start}**: Normal telemetry baseline confirmed.
- **{anomaly_start}**: Anomaly window onset. Initial deviation detected on `{root_cause_endpoint}`.
- **{incident_time}**: Incident logged by AIOps detection engine (`{incident['incident_id']}`).
- **{anomaly_end}**: Telemetry data indicates end of peak anomaly or dataset cutoff.

## 4. Visual Evidence
*(Graph generated dynamically. Please see `incident_timeline.png` for plotted latency and error metrics over this window.)*

![Incident Timeline](incident_timeline.png)

## 5. Recommended Actions
1. {rca_doc['recommended_action']}
2. Implement strict rate limiting or circuit breaking on `{root_cause_endpoint}`.
3. Review logging and metric collection around this endpoint for deeper context.
"""
    
    with open(os.path.join(lab4_dir, 'RCA_REPORT.md'), 'w') as f:
        f.write(md_content)
    print("Generated RCA_REPORT.md")

if __name__ == "__main__":
    run_rca()
