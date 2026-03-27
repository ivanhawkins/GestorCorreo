"""
Test API endpoint for creating accounts
"""
import requests
import json

# First, login to get token
login_data = {
    "username": "admin",
    "password": "admin123"
}

response = requests.post("http://localhost:8000/api/auth/token", data=login_data)
print(f"Login status: {response.status_code}")

if response.status_code == 200:
    token = response.json()["access_token"]
    print(f"✅ Got token: {token[:20]}...")
    
    # Now try to create account
    headers = {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json"
    }
    
    account_data = {
        "email_address": "test@ionos.es",
        "imap_host": "pop.ionos.es",
        "imap_port": 995,
        "smtp_host": "smtp.ionos.es",
        "smtp_port": 587,
        "username": "test@ionos.es",
        "password": "test123",
        "protocol": "pop3"
    }
    
    response = requests.post(
        "http://localhost:8000/api/accounts/",
        headers=headers,
        json=account_data
    )
    
    print(f"\nCreate account status: {response.status_code}")
    print(f"Response: {response.text}")
    
    if response.status_code == 201:
        print("✅ Account created successfully!")
        account_id = response.json()["id"]
        
        # Delete it
        delete_response = requests.delete(
            f"http://localhost:8000/api/accounts/{account_id}",
            headers=headers,
            params={"permanent": True}
        )
        print(f"Delete status: {delete_response.status_code}")
    else:
        print(f"❌ Failed to create account")
else:
    print(f"❌ Login failed: {response.text}")
