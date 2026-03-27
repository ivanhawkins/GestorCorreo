"""
Test the GET /api/ai-config endpoint with authentication
"""
import requests

# This would be a real token from login
url = "http://localhost:8000/api/ai-config"

# Try without auth first
print("1. Testing WITHOUT auth...")
try:
    response = requests.get(url)
    print(f"   Status: {response.status_code}")
    print(f"   Response: {response.text[:200]}")
except Exception as e:
    print(f"   Error: {e}")

# Login and get token
print("\n2. Logging in to get token...")
try:
    login_response = requests.post(
        "http://localhost:8000/api/auth/token",
        data={"username": "admin", "password": "admin"}
    )
    print(f"   Login Status: {login_response.status_code}")
    if login_response.status_code == 200:
        token = login_response.json().get("access_token")
        print(f"   Token: {token[:30]}...")
        
        # Now try with auth
        print("\n3. Testing WITH auth...")
        headers = {"Authorization": f"Bearer {token}"}
        response = requests.get(url, headers=headers)
        print(f"   Status: {response.status_code}")
        print(f"   Response: {response.json()}")
except Exception as e:
    print(f"   Error: {e}")
