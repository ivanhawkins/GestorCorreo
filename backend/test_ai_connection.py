"""
Test script to verify AI configuration and RemoteAIClient.
"""
import asyncio
import sys
sys.path.insert(0, '.')

from app.services.ai_service import RemoteAIClient

async def test_connection():
    """Test connection to remote AI API."""
    api_url = "https://192.168.1.45/chat/models"
    api_key = "OllamaAPI_2024_K8mN9pQ2rS5tU7vW3xY6zA1bC4eF8hJ0lM"
    
    print(f"Testing connection to: {api_url}")
    print(f"Using API key: {api_key[:20]}...")
    
    client = RemoteAIClient(api_url, api_key)
    
    # Test 1: Health check
    print("\n1. Testing health check...")
    try:
        healthy = await client.check_health()
        print(f"   ✓ Health check: {'OK' if healthy else 'FAILED'}")
    except Exception as e:
        print(f"   ✗ Health check failed: {e}")
    
    # Test 2: List models
    print("\n2. Testing list models...")
    try:
        models = await client.list_models()
        print(f"   ✓ Found {len(models)} models:")
        for model in models[:5]:  # Show first 5
            print(f"      - {model}")
    except Exception as e:
        print(f"   ✗ List models failed: {e}")
    
    # Test 3: Generate (classification test)
    print("\n3. Testing classification generation...")
    try:
        response = await client.generate(
            model="gpt-oss:120b-cloud",
            prompt='Classify this email: "Meeting reminder for tomorrow at 3pm"',
            timeout=10.0
        )
        print(f"   ✓ Generation successful:")
        print(f"      Response: {response.get('response', '')[:100]}...")
    except Exception as e:
        print(f"   ✗ Generation failed: {e}")

if __name__ == "__main__":
    asyncio.run(test_connection())
