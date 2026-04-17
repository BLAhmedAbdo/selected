import requests
import random
import time
import json
import datetime

def run_traffic_generator():
    urls = [
        {"url": "http://127.0.0.1:8000/api/normal", "method": "GET", "weight_normal": 70, "weight_anomaly": 10},
        {"url": "http://127.0.0.1:8000/api/slow", "method": "GET", "weight_normal": 15, "weight_anomaly": 5},
        {"url": "http://127.0.0.1:8000/api/slow?hard=1", "method": "GET", "weight_normal": 5, "weight_anomaly": 40}, # Latency Spike Anomaly
        {"url": "http://127.0.0.1:8000/api/error", "method": "GET", "weight_normal": 5, "weight_anomaly": 35}, # Error Spike Anomaly
        {"url": "http://127.0.0.1:8000/api/db?fail=1", "method": "GET", "weight_normal": 3, "weight_anomaly": 5},
        {"url": "http://127.0.0.1:8000/api/validate", "method": "POST", "weight_normal": 2, "weight_anomaly": 5},
    ]

    total_duration_minutes = 10
    total_requests = 3000
    requests_per_second = total_requests / (total_duration_minutes * 60)
    sleep_interval = 1.0 / requests_per_second

    anomaly_start_min = 6
    anomaly_end_min = 8
    
    start_time = datetime.datetime.now()
    anomaly_start_time = start_time + datetime.timedelta(minutes=anomaly_start_min)
    anomaly_end_time = start_time + datetime.timedelta(minutes=anomaly_end_min)

    print(f"Starting traffic generator...")
    print(f"Total time: {total_duration_minutes} minutes.")
    print(f"Total requests: {total_requests}")
    print(f"Anomaly Window: {anomaly_start_time.isoformat()} to {anomaly_end_time.isoformat()}")

    ground_truth = {
        "anomaly_start_iso": anomaly_start_time.isoformat(),
        "anomaly_end_iso": anomaly_end_time.isoformat(),
        "anomaly_type": "latency_and_error_spike",
        "expected_behavior": "Traffic shifts from mostly normal to heavily slow and error-prone during the anomaly window."
    }

    with open("ground_truth.json", "w") as f:
        json.dump(ground_truth, f, indent=4)

    for i in range(total_requests):
        now = datetime.datetime.now()
        is_anomaly = anomaly_start_time <= now <= anomaly_end_time

        weights = [u["weight_anomaly"] if is_anomaly else u["weight_normal"] for u in urls]
        target = random.choices(urls, weights=weights, k=1)[0]

        try:
            if target["method"] == "GET":
                requests.get(target["url"], timeout=10)
            elif target["method"] == "POST":
                # 50% invalid payload for validate endpoint
                valid = random.choice([True, False])
                payload = {"email": "test@test.com", "age": 30} if valid else {"email": "invalid", "age": 0}
                requests.post(target["url"], json=payload, timeout=10)
        except requests.exceptions.RequestException:
            pass

        time.sleep(sleep_interval)

    print("Traffic generation complete.")

if __name__ == "__main__":
    run_traffic_generator()