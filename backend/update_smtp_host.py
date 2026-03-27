import asyncio
import sys
import os

# Add backend to path
sys.path.append(os.path.join(os.path.dirname(__file__), "app"))

from app.database import AsyncSessionLocal
from app.models import Account
from sqlalchemy import select

async def update_smtp():
    async with AsyncSessionLocal() as db:
        print("Fetching accounts...")
        result = await db.execute(select(Account).where(Account.email_address.like("%@hawkins.es")))
        accounts = result.scalars().all()
        
        if not accounts:
            print("No accounts found for hawkins.es.")
            return

        for account in accounts:
            print(f"Updating Account {account.id} ({account.email_address})...")
            print(f"  Old SMTP: {account.smtp_host}:{account.smtp_port}")
            
            # Update settings
            account.smtp_host = "smtp.ionos.es"
            account.smtp_port = 465
            
            # Also update IMAP
            account.imap_host = "imap.ionos.es" 
            account.imap_port = 993

        await db.commit()
        print(f"Updated {len(accounts)} accounts.")

if __name__ == "__main__":
    if sys.platform == 'win32':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    asyncio.run(update_smtp())
