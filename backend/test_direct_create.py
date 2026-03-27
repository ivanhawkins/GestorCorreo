"""
Simple test to directly call the create account endpoint
"""
import asyncio
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from app.database import AsyncSessionLocal
from app.models import User
from app.schemas import AccountCreate
from app.routers.accounts import create_account
from sqlalchemy import select

async def test_create():
    async with AsyncSessionLocal() as db:
        # Get user
        result = await db.execute(select(User).limit(1))
        user = result.scalar_one()
        
        # Create account data
        account_data = AccountCreate(
            email_address="test@ionos.es",
            imap_host="pop.ionos.es",
            imap_port=995,
            smtp_host="smtp.ionos.es",
            smtp_port=587,
            username="test@ionos.es",
            password="test123",
            protocol="pop3"
        )
        
        try:
            account = await create_account(account_data, user, db)
            print(f"✅ Success! Account ID: {account.id}")
        except Exception as e:
            print(f"❌ Error: {type(e).__name__}: {str(e)}")
            import traceback
            traceback.print_exc()

if __name__ == "__main__":
    asyncio.run(test_create())
