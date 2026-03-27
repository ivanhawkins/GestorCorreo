import asyncio
import sys
from pathlib import Path
from sqlalchemy import select, func
from sqlalchemy.orm import selectinload

# Add parent directory to path to allow imports
sys.path.insert(0, str(Path(__file__).parent))

from app.database import AsyncSessionLocal, init_db
from app.models import Account, Message, Attachment

async def recalculate_storage():
    """
    Recalculate storage usage for all accounts.
    """
    print("Starting storage recalculation...")
    
    async with AsyncSessionLocal() as db:
        # Get all accounts
        result = await db.execute(select(Account))
        accounts = result.scalars().all()
        
        for account in accounts:
            print(f"Processing account: {account.email_address} (ID: {account.id})...")
            
            # Calculate total message body size
            # We approximate by summing length of body_text and body_html
            # Note: stored as Text, so length is roughly bytes (utf-8 varies but this is an estimate)
            
            # Sum body_text
            q_text = select(func.sum(func.length(Message.body_text))).where(Message.account_id == account.id)
            res_text = await db.execute(q_text)
            total_text = res_text.scalar() or 0
            
            # Sum body_html
            q_html = select(func.sum(func.length(Message.body_html))).where(Message.account_id == account.id)
            res_html = await db.execute(q_html)
            total_html = res_html.scalar() or 0
            
            # Sum attachments size
            # Join Message -> Attachment
            q_att = select(func.sum(Attachment.size_bytes)).join(Message).where(Message.account_id == account.id)
            res_att = await db.execute(q_att)
            total_attachments = res_att.scalar() or 0
            
            total_size = total_text + total_html + total_attachments
            
            print(f"  - Text: {total_text} bytes")
            print(f"  - HTML: {total_html} bytes")
            print(f"  - Attachments: {total_attachments} bytes")
            print(f"  = Total: {total_size} bytes")
            
            # Update account
            account.mailbox_storage_bytes = total_size
            
        await db.commit()
        print("Storage recalculation complete!")

if __name__ == "__main__":
    asyncio.run(recalculate_storage())
