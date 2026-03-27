import asyncio
import sys
import os

# Add backend to path
sys.path.append(os.path.join(os.path.dirname(__file__), "app"))

from app.database import AsyncSessionLocal
from app.models import Account
from sqlalchemy import select

async def list_accounts():
    async with AsyncSessionLocal() as db:
        result = await db.execute(select(Account))
        accounts = result.scalars().all()
        
        output = []
        output.append(f"Found {len(accounts)} accounts:")
        for acc in accounts:
           output.append(f"ID: {acc.id} | Email: {acc.email_address} | Host: {acc.smtp_host}:{acc.smtp_port} | UserID: {acc.user_id}")
        
        with open("accounts_list.txt", "w") as f:
            f.write("\n".join(output))
        print("Written to accounts_list.txt")

if __name__ == "__main__":
    if sys.platform == 'win32':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    asyncio.run(list_accounts())
