import asyncio
import sys
import os

# Add backend to path
sys.path.append(os.path.join(os.path.dirname(__file__), "app"))

from app.database import AsyncSessionLocal
from app.models import Account
from sqlalchemy import select
from app.utils.security import decrypt_password
from app.services.smtp_service import SMTPService

async def test_smtp():
    async with AsyncSessionLocal() as db:
        print("Fetching account...")
        result = await db.execute(select(Account))
        account = result.scalars().first()
        
        if not account:
            print("No account found.")
            return

        output_lines = []
        output_lines.append(f"Account: {account.email_address}")
        output_lines.append(f"IMAP: {account.imap_host}:{account.imap_port}")
        output_lines.append(f"SMTP: {account.smtp_host}:{account.smtp_port}")
        
        try:
            password = decrypt_password(account.encrypted_password)
            output_lines.append("Password decrypted.")
        except Exception as e:
            output_lines.append(f"Decryption failed: {e}")
            with open("debug_smtp_output.txt", "w") as f:
                f.write("\n".join(output_lines))
            return

        # Try smtp.ionos.es port 465
        test_host = "smtp.ionos.es"
        test_port = 465
        output_lines.append(f"Testing with host: {test_host}:{test_port}")

        smtp = SMTPService(
            host=test_host,
            port=test_port,
            username=account.username,
            password=password
        )
        
        output_lines.append("Attempting to send email...")
        try:
            result = await smtp.send_email(
                to_addresses=[account.email_address], # Send to self
                subject="Test SMTP Debug",
                body_text="This is a test."
            )
            output_lines.append(f"Result: {result}")
        except Exception as e:
            output_lines.append(f"Exception during send: {e}")
            
        with open("debug_smtp_output.txt", "w") as f:
            f.write("\n".join(output_lines))

if __name__ == "__main__":
    if sys.platform == 'win32':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    asyncio.run(test_smtp())
