"""
Utilities for initializing data on application startup.
"""
import os
import logging
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from app.models import Account, User
from app.utils.security import encrypt_password

logger = logging.getLogger(__name__)

async def init_default_account(db: AsyncSession) -> None:
    """
    Initialize a default account from environment variables if it doesn't exist.
    """
    email = os.getenv("DEFAULT_EMAIL_ADDRESS")
    if not email:
        logger.info("No DEFAULT_EMAIL_ADDRESS set, skipping default account creation.")
        return

    # Check for admin user
    result_user = await db.execute(select(User).where(User.username == "admin"))
    admin_user = result_user.scalar_one_or_none()
    
    if not admin_user:
        logger.warning("Admin user not found. Run migration first. Skipping default account.")
        return

    # Check if account exists
    result = await db.execute(select(Account).where(Account.email_address == email))
    existing_account = result.scalar_one_or_none()

    if existing_account:
        logger.info(f"Default account {email} already exists.")
        return

    logger.info(f"Creating default account for {email}...")

    try:
        password = os.getenv("DEFAULT_PASSWORD", "")
        encrypted_password = encrypt_password(password)

        new_account = Account(
            user_id=admin_user.id,
            email_address=email,
            username=os.getenv("DEFAULT_USERNAME", email),
            encrypted_password=encrypted_password,
            imap_host=os.getenv("DEFAULT_IMAP_HOST", ""),
            imap_port=int(os.getenv("DEFAULT_IMAP_PORT", 993)),
            smtp_host=os.getenv("DEFAULT_SMTP_HOST", ""),
            smtp_port=int(os.getenv("DEFAULT_SMTP_PORT", 587)),
            is_active=True,
            ssl_verify=True,
            connection_timeout=30
        )

        db.add(new_account)
        await db.commit()
        logger.info(f"Successfully created default account: {email}")

    except Exception as e:
        logger.error(f"Failed to create default account: {e}")
        await db.rollback()
