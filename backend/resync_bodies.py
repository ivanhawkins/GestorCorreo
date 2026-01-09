"""
Script to re-sync existing messages and download their body content.
This will update messages that have null body_text and body_html.
"""
import asyncio
import sys
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent))

from app.database import get_db
from app.models import Account, Message
from app.services.imap_service import IMAPService
from app.utils.security import decrypt_password
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession


async def resync_message_bodies():
    """Re-download body content for existing messages."""
    print("Starting message body re-sync...")
    
    async for db in get_db():
        # Get all accounts
        result = await db.execute(select(Account).where(Account.is_active == True))
        accounts = result.scalars().all()
        
        print(f"Found {len(accounts)} active accounts")
        
        for account in accounts:
            print(f"\nProcessing account: {account.email_address}")
            
            # Decrypt password
            try:
                password = decrypt_password(account.encrypted_password)
            except Exception as e:
                print(f"  ✗ Failed to decrypt password: {e}")
                continue
            
            # Connect to IMAP
            imap = IMAPService(account, password)
            try:
                if not imap.connect():
                    print(f"  ✗ Failed to connect to IMAP")
                    continue
                
                print(f"  ✓ Connected to IMAP")
                
                # Select INBOX
                if not imap.select_folder("INBOX"):
                    print(f"  ✗ Failed to select INBOX")
                    imap.disconnect()
                    continue
                
                # Get messages without body
                result = await db.execute(
                    select(Message)
                    .where(Message.account_id == account.id)
                    .where(Message.body_text == None)
                    .where(Message.body_html == None)
                )
                messages = result.scalars().all()
                
                print(f"  Found {len(messages)} messages without body content")
                
                if len(messages) == 0:
                    imap.disconnect()
                    continue
                
                # Update each message
                updated_count = 0
                for message in messages:
                    try:
                        # Fetch full body
                        body_data = imap.fetch_full_message_body(message.imap_uid)
                        
                        if body_data:
                            message.body_text = body_data.get('body_text')
                            message.body_html = body_data.get('body_html')
                            message.has_attachments = len(body_data.get('attachments', [])) > 0
                            updated_count += 1
                            
                            if updated_count % 10 == 0:
                                print(f"    Updated {updated_count} messages...")
                                await db.commit()
                    
                    except Exception as e:
                        print(f"    ✗ Error updating message {message.id}: {e}")
                        continue
                
                # Final commit
                await db.commit()
                print(f"  ✓ Updated {updated_count} messages")
                
                imap.disconnect()
            
            except Exception as e:
                print(f"  ✗ Error processing account: {e}")
                imap.disconnect()
                continue
    
    print("\n✓ Message body re-sync completed!")


if __name__ == "__main__":
    asyncio.run(resync_message_bodies())
