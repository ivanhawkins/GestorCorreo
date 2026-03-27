import asyncio
import sys
import os

# Add backend to path
sys.path.append(os.path.join(os.path.dirname(__file__), "app"))

from app.database import AsyncSessionLocal
from app.models import Account
from sqlalchemy import select

async def fix_incoming():
    async with AsyncSessionLocal() as db:
        print("Fetching accounts...")
        result = await db.execute(select(Account).where(Account.email_address.like("%@hawkins.es")))
        accounts = result.scalars().all()
        
        if not accounts:
            print("No accounts found.")
            return

        for account in accounts:
            print(f"\nAccount {account.id} ({account.email_address})")
            print(f"  Protocol: {account.protocol}")
            print(f"  Current Host: {account.imap_host}:{account.imap_port}")
            
            if account.protocol == 'pop3':
                print("  -> Detected POP3. Setting to pop.ionos.es:995")
                account.imap_host = "pop.ionos.es"
                account.imap_port = 995
                # Also ensure SMTP is still correct
                account.smtp_host = "smtp.ionos.es"
                account.smtp_port = 465
            elif account.protocol == 'imap':
                print("  -> Detected IMAP. Check/Setting to imap.ionos.es:993")
                account.imap_host = "imap.ionos.es"
                account.imap_port = 993
                account.smtp_host = "smtp.ionos.es"
                account.smtp_port = 465
            else:
                 # Default logic if protocol is missing (assume IMAP or check port?)
                 # For now, if port was 995 before, it might be POP3?
                 # Safe bet: assume IMAP if unknown, or ask user?
                 # Given the error was "Failed to connect to POP3 server", the protocol IS set to pop3.
                 print(f"  -> Unknown protocol '{account.protocol}'. Setting default IMAP.")
                 account.imap_host = "imap.ionos.es"
                 account.imap_port = 993
        
        await db.commit()
        print("\nUpdate complete.")

if __name__ == "__main__":
    if sys.platform == 'win32':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    asyncio.run(fix_incoming())
