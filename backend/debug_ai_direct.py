
import asyncio
import httpx
import json

# Config
API_URL = "https://192.168.1.45/chat/chat"
API_KEY = "OllamaAPI_2024_K8mN9pQ2rS5tU7vW3xY6zA1bC4eF8hJ0lM"
MODEL = "gpt-oss:120b-cloud"

async def debug_request():
    print(f"--- DEBUGGING AI ENDPOINT: {API_URL} ---")
    print(f"Model: {MODEL}")
    
    payload = {
        "modelo": MODEL,
        "prompt": "Say hello in 5 words."
    }
    
    print(f"Payload: {json.dumps(payload, indent=2)}")
    
    try:
        async with httpx.AsyncClient(verify=False, timeout=30.0) as client:
            print("\nSending request...")
            response = await client.post(
                API_URL,
                headers={"x-api-key": API_KEY},
                json=payload
            )
            
            print(f"\nStatus Code: {response.status_code}")
            print("Headers:")
            for k, v in response.headers.items():
                print(f"  {k}: {v}")
                
            print("\nRaw Response Text:")
            print(f"'{response.text}'")
            
            print("\nTrying JSON decode:")
            try:
                data = response.json()
                print(json.dumps(data, indent=2))
            except Exception as e:
                print(f"JSON Decode Failed: {e}")

    except Exception as e:
        print(f"\nFATAL ERROR: {e}")

if __name__ == "__main__":
    asyncio.run(debug_request())
