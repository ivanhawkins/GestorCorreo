import asyncio
import sys
import os
import warnings

# Suppress warnings
warnings.filterwarnings("ignore")

# Add backend to path
sys.path.append(os.path.join(os.getcwd(), 'backend'))

from app.database import AsyncSessionLocal
from app.models import Account, Message
from sqlalchemy import select
import logging

# Disable sqlalchemy logging
logging.getLogger('sqlalchemy.engine').setLevel(logging.ERROR)

async def list_accounts():
    async with AsyncSessionLocal() as db:
        print("--- START DEBUG ---", flush=True)
        result = await db.execute(select(Account))
        accounts = result.scalars().all()
        print(f"Found {len(accounts)} accounts:", flush=True)
        for acc in accounts:
            print(f"ID: {acc.id}, Email: {acc.email_address}", flush=True)
            
            # Check last message UID
            res = await db.execute(
                select(Message)
                .where(Message.account_id == acc.id)
                .order_by(Message.imap_uid.desc())
                .limit(5)
            )
            last_msgs = res.scalars().all()
            print(f"  Top 5 UIDs: {[m.imap_uid for m in last_msgs]}", flush=True)
            
            # Count messages
            res_count = await db.execute(select(Message).where(Message.account_id == acc.id))
            count = len(res_count.scalars().all())
            print(f"  Total Local Messages: {count}", flush=True)
        print("--- END DEBUG ---", flush=True)

if __name__ == "__main__":
    if os.name == 'nt':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    asyncio.run(list_accounts())
