import json
import pandas as pd
import numpy as np
from sklearn.ensemble import IsolationForest
import matplotlib.pyplot as plt

def load_data(filepath='logs.json'):
    with open(filepath, 'r') as f:
        data = json.load(f)
    df = pd.DataFrame(data)
    df['timestamp'] = pd.to_datetime(df['timestamp'])
    return df

def extract_features(df, window_seconds=30):
    df = df.sort_values('timestamp')
    df.set_index('timestamp', inplace=True)
    
    features = []
    
    # Iterate through each endpoint
    for endpoint, endpoint_df in df.groupby('path'):
        # Resample into time windows
        resampled = endpoint_df.resample(f'{window_seconds}S')
        
        avg_latency = resampled['latency_ms'].mean().fillna(0)
        max_latency = resampled['latency_ms'].max().fillna(0)
        latency_std = resampled['latency_ms'].std().fillna(0)
        
        request_rate = resampled['status_code'].count()
        
        # Calculate errors (status >= 400 or category is not null)
        errors = endpoint_df[endpoint_df['error_category'].notnull()]
        errors_per_window = errors.resample(f'{window_seconds}S')['status_code'].count()
        # Align with request_rate index
        errors_per_window = errors_per_window.reindex(request_rate.index).fillna(0)
        
        error_rate = (errors_per_window / request_rate).fillna(0)
        
        # Assemble feature frame for this endpoint
        feature_df = pd.DataFrame({
            'endpoint': endpoint,
            'avg_latency': avg_latency,
            'max_latency': max_latency,
            'latency_std': latency_std,
            'request_rate': request_rate,
            'errors_per_window': errors_per_window,
            'error_rate': error_rate
        })
        features.append(feature_df)
        
    final_df = pd.concat(features).reset_index()
    # Add endpoint_frequency (total requests for endpoint / total requests overall) in that window
    total_requests_per_window = final_df.groupby('timestamp')['request_rate'].sum()
    final_df['endpoint_frequency'] = final_df.apply(
        lambda row: row['request_rate'] / total_requests_per_window[row['timestamp']] if total_requests_per_window[row['timestamp']] > 0 else 0,
        axis=1
    )
    
    return final_df

def detect_anomalies(features_df):
    model_features = ['avg_latency', 'max_latency', 'latency_std', 'request_rate', 'errors_per_window', 'error_rate', 'endpoint_frequency']
    
    # Identify normal behavior period - let's say the first 7 minutes (420 seconds) are normal
    start_time = features_df['timestamp'].min()
    normal_end_time = start_time + pd.Timedelta(seconds=420)
    
    normal_data = features_df[features_df['timestamp'] <= normal_end_time]
    
    # Train Isolation Forest on normal data
    # Contamination defines the proportion of outliers in the data set
    model = IsolationForest(n_estimators=100, contamination=0.01, random_state=42)
    model.fit(normal_data[model_features])
    
    # Predict on all data
    predictions = model.predict(features_df[model_features])
    # calculate anomaly score (lower means more anomalous, let's reverse it so higher = anomaly)
    scores = model.score_samples(features_df[model_features])
    
    # IsolationForest outputs -1 for outlier, 1 for inlier
    features_df['is_anomaly'] = np.where(predictions == -1, True, False)
    features_df['anomaly_score'] = -scores # Reversing score so higher is more anomalous
    
    return features_df

def visualize_results(features_df):
    # Overall latency timeline
    plt.figure(figsize=(15, 6))
    plt.plot(features_df['timestamp'], features_df['avg_latency'], alpha=0.5, label='Avg Latency')
    anomalies = features_df[features_df['is_anomaly']]
    plt.scatter(anomalies['timestamp'], anomalies['avg_latency'], color='red', label='Anomalies')
    plt.title('Latency Timeline with Anomalies Highlighted')
    plt.xlabel('Time')
    plt.ylabel('Latency (ms)')
    plt.legend()
    plt.grid(True)
    plt.savefig('latency_timeline.png')
    plt.close()
    
    # Error rate timeline
    plt.figure(figsize=(15, 6))
    plt.plot(features_df['timestamp'], features_df['error_rate'], alpha=0.5, label='Error Rate')
    plt.scatter(anomalies['timestamp'], anomalies['error_rate'], color='red', label='Anomalies')
    plt.title('Error Rate Timeline with Anomalies Highlighted')
    plt.xlabel('Time')
    plt.ylabel('Error Rate')
    plt.legend()
    plt.grid(True)
    plt.savefig('error_rate_timeline.png')
    plt.close()

def main():
    print("Loading data...")
    df = load_data('logs.json')
    
    print("Extracting features...")
    features_df = extract_features(df, window_seconds=30)
    features_df.to_csv('aiops_dataset.csv', index=False)
    print(f"Exported aiops_dataset.csv with {len(features_df)} records.")
    
    print("Training model and predicting...")
    results_df = detect_anomalies(features_df)
    
    predictions_df = results_df[['timestamp', 'endpoint', 'anomaly_score', 'is_anomaly']]
    predictions_df.to_csv('anomaly_predictions.csv', index=False)
    print(f"Exported anomaly_predictions.csv with {len(predictions_df)} records.")
    
    print("Generating Visualizations...")
    visualize_results(results_df)
    print("Saved plots to latency_timeline.png and error_rate_timeline.png")

if __name__ == "__main__":
    main()
