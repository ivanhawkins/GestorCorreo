import asyncio
import sys
import os
import warnings
from sqlalchemy import select

# Suppress warnings
warnings.filterwarnings("ignore")

# Add backend to path
sys.path.append(os.path.join(os.getcwd(), 'backend'))

from app.database import AsyncSessionLocal
from app.models import Account
from app.utils.security import decrypt_password
from app.services.imap_service import sync_account_messages

async def run_sync():
    account_id = 4
    print(f"Syncing Account ID {account_id}...")
    
    async with AsyncSessionLocal() as db:
        # Fetch account
        result = await db.execute(select(Account).where(Account.id == account_id))
        account = result.scalar_one_or_none()
        
        if not account:
            print("Account not found")
            return
            
        try:
            password = decrypt_password(account.encrypted_password)
        except Exception as e:
            print(f"Decrypt Error: {e}")
            return
            
        print(f"Decrypted password for {account.email_address}. Starting sync...")
        
        try:
            async for progress in sync_account_messages(account, password, db):
                # Print progress to stdout (which I will capture)
                if progress.get('status') == 'downloading':
                    print(f"Downloading: {progress.get('current')}/{progress.get('total')}")
                elif progress.get('status') == 'error':
                    print(f"Error: {progress.get('error')}")
                elif progress.get('status') == 'success':
                    print(f"Success! New: {progress.get('new_messages')}")
                elif progress.get('status') == 'found_messages':
                    print(f"Found {progress.get('total')} new messages.")
                    
        except Exception as e:
            print(f"Sync Loop Error: {e}")

if __name__ == "__main__":
    if os.name == 'nt':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    asyncio.run(run_sync())
