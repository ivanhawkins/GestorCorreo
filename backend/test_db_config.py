"""
Quick test to check GET /api/ai-config endpoint
"""
import asyncio
import sys
sys.path.insert(0, '.')

from app.database import SessionLocal
from app.models import AIConfig
from sqlalchemy import select

async def test_get_config():
    async with SessionLocal() as db:
        result = await db.execute(select(AIConfig).limit(1))
        config = result.scalar_one_or_none()
        
        if config:
            print("✓ Config found in DB:")
            print(f"  ID: {config.id}")
            print(f"  API URL: {config.api_url}")
            print(f"  Primary Model: {config.primary_model}")
            print(f"  Secondary Model: {config.secondary_model}")
            print(f"  API Key: {config.api_key[:20]}...")
        else:
            print("✗ No config found")

if __name__ == "__main__":
    asyncio.run(test_get_config())
