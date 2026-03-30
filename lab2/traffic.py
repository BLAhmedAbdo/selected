import requests
import random
import time

urls = [
    "http://127.0.0.1:8000/api/normal",
    "http://127.0.0.1:8000/api/slow",
    "http://127.0.0.1:8000/api/error",
]

for i in range(3000):

    url = random.choice(urls)

    try:
        requests.get(url)
    except:
        pass

    time.sleep(0.2)