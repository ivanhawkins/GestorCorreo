"""
Test account creation directly to debug the issue
"""
import asyncio
import sys
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent))

from app.database import AsyncSessionLocal
from app.models import User, Account
from app.utils.security import encrypt_password
from sqlalchemy import select

async def test_create_account():
    async with AsyncSessionLocal() as db:
        # Get first user
        result = await db.execute(select(User).limit(1))
        user = result.scalar_one_or_none()
        
        if not user:
            print("❌ No user found in database")
            return
            
        print(f"✅ Found user: {user.username}")
        
        # Create test account
        encrypted_password = encrypt_password("test_password_123")
        
        account = Account(
            user_id=user.id,
            email_address="test@ionos.es",
            imap_host="pop.ionos.es",
            imap_port=995,
            smtp_host="smtp.ionos.es",
            smtp_port=587,
            username="test@ionos.es",
            encrypted_password=encrypted_password,
            is_active=True,
            ssl_verify=True,
            connection_timeout=30,
            auto_classify=False,
            auto_sync_interval=0,
            protocol='pop3',
            is_deleted=False
        )
        
        try:
            db.add(account)
            await db.commit()
            await db.refresh(account)
            print(f"✅ Account created successfully! ID: {account.id}")
            
            # Clean up
            await db.delete(account)
            await db.commit()
            print("✅ Test account deleted")
            
        except Exception as e:
            print(f"❌ Error creating account: {e}")
            import traceback
            traceback.print_exc()

if __name__ == "__main__":
    asyncio.run(test_create_account())
