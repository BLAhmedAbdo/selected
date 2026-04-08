import json
import random
import uuid
import datetime

def generate_logs():
    records = []
    
    # 8 minutes of normal traffic, 2 minutes of anomaly traffic
    # Total 10 minutes = 600 seconds. Let's say ~3 requests per second = ~1800 requests.
    
    endpoints = [
        {"path": "/api/normal", "method": "GET"},
        {"path": "/api/slow", "method": "GET"},
        {"path": "/api/slow?hard=1", "method": "GET"},
        {"path": "/api/error", "method": "GET"},
        {"path": "/api/db", "method": "GET"},
        {"path": "/api/validate", "method": "POST"},
    ]
    
    start_time = datetime.datetime.now() - datetime.timedelta(minutes=10)
    
    for i in range(1800):
        current_time = start_time + datetime.timedelta(seconds=i / 3.0)
        
        # Anomaly window between minute 7 and 9 (2 minutes)
        is_anomaly = (420 <= i / 3.0 <= 540)
        
        if is_anomaly:
            # Error spike anomaly: raise /api/error significantly
            weights = [30, 10, 5, 45, 5, 5]
        else:
            # Normal distribution: 70% normal, 15% slow, 5% hard slow, 5% error, 3% db, 2% validate
            weights = [70, 15, 5, 5, 3, 2]
            
        endpoint = random.choices(endpoints, weights=weights, k=1)[0]
        
        latency_ms = random.uniform(50, 200)
        status_code = 200
        error_category = None
        severity = "info"
        
        if endpoint["path"] == "/api/normal":
            pass
        elif endpoint["path"] == "/api/slow":
            latency_ms = random.uniform(2000, 2500)
        elif endpoint["path"] == "/api/slow?hard=1":
            latency_ms = random.uniform(5000, 7000)
            error_category = "TIMEOUT_ERROR"
            severity = "error"
        elif endpoint["path"] == "/api/error":
            status_code = 500
            error_category = "SYSTEM_ERROR"
            severity = "error"
        elif endpoint["path"] == "/api/db":
            if random.random() < 0.1: # 10% db error
                status_code = 500
                error_category = "DATABASE_ERROR"
                severity = "error"
        elif endpoint["path"] == "/api/validate":
            if random.random() < 0.5: # 50% invalid
                status_code = 422
                error_category = "VALIDATION_ERROR"
                severity = "error"
                
        record = {
            "timestamp": current_time.isoformat(),
            "request_id": str(uuid.uuid4()),
            "method": endpoint["method"],
            "path": endpoint["path"].split("?")[0], # clean path for endpoint
            "status_code": status_code,
            "latency_ms": latency_ms,
            "client_ip": "192.168.1." + str(random.randint(1, 255)),
            "user_agent": "Mozilla/5.0",
            "query": "hard=1" if "?hard=1" in endpoint["path"] else "",
            "payload_size_bytes": 0 if endpoint["method"] == "GET" else random.randint(10, 100),
            "response_size_bytes": random.randint(100, 1000),
            "route_name": "unknown",
            "error_category": error_category,
            "severity": severity,
            "build_version": "1.0.0",
            "host": "localhost"
        }
        records.append(record)
        
    with open("logs.json", "w") as f:
        json.dump(records, f, indent=2)
        
    print(f"Generated logs.json with {len(records)} records")
    
if __name__ == "__main__":
    generate_logs()
