import asyncio
from sqlalchemy import select
from app.database import AsyncSessionLocal
from app.models import Attachment
import sys
import logging

# Suppress logging
logging.getLogger('sqlalchemy').setLevel(logging.WARNING)

# Windows path fix for asyncio
if sys.platform == 'win32':
    asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())

async def inspect_attachment(att_id):
    async with AsyncSessionLocal() as db:
        result = await db.execute(select(Attachment).where(Attachment.id == att_id))
        att = result.scalar_one_or_none()
        if att:
            print(f"\n--- ATTACHMENT DETAILS ---")
            print(f"Attachment ID: {att.id}")
            print(f"Filename: {att.filename}")
            print(f"Local Path: '{att.local_path}'")
            print(f"Mime Type: {att.mime_type}")
            print(f"--------------------------\n")
        else:
            print("Attachment not found")

if __name__ == "__main__":
    asyncio.run(inspect_attachment(447))
