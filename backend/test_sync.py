import requests
import json

# Test sync endpoint
url = "http://localhost:8000/api/sync/start"
data = {"account_id": 1}

print(f"Testing POST {url}")
print(f"Data: {json.dumps(data)}")

try:
    response = requests.post(url, json=data)
    print(f"Status: {response.status_code}")
    print(f"Response: {response.text}")
except Exception as e:
    print(f"Error: {e}")
