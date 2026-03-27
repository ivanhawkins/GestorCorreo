import httpx
import sys

def verify():
    # 1. Login
    try:
        resp = httpx.post("http://localhost:8000/api/auth/token", data={"username": "admin", "password": "admin"})
        if resp.status_code != 200:
            print(f"Login failed: {resp.text}")
            sys.exit(1)
        
        token = resp.json()["access_token"]
        print("Login successful.")
        
        # 2. Get Users
        headers = {"Authorization": f"Bearer {token}"}
        resp = httpx.get("http://localhost:8000/api/users/", headers=headers)
        
        if resp.status_code != 200:
            print(f"Get users failed: {resp.text}")
            sys.exit(1)
            
        users = resp.json()
        print(f"Found {len(users)} users.")
        
        for user in users:
            usage = user.get("mailbox_usage_bytes")
            print(f"User {user['username']} usage: {usage} bytes")
            if usage is None:
                print("FAIL: mailbox_usage_bytes is missing!")
                sys.exit(1)
                
        print("verification passed!")
        
    except Exception as e:
        print(f"Error: {e}")
        sys.exit(1)

if __name__ == "__main__":
    verify()
