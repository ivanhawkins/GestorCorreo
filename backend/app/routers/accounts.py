"""
Router for account management endpoints.
"""
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from typing import List

from app.database import get_db
from app.models import Account
from app.schemas import AccountCreate, AccountUpdate, AccountResponse
from app.utils.security import encrypt_password, decrypt_password
from app.services.imap_service import IMAPService


router = APIRouter()


@router.get("/", response_model=List[AccountResponse])
async def list_accounts(db: AsyncSession = Depends(get_db)):
    """List all email accounts."""
    result = await db.execute(select(Account))
    accounts = result.scalars().all()
    return accounts


@router.get("/{account_id}", response_model=AccountResponse)
async def get_account(account_id: int, db: AsyncSession = Depends(get_db)):
    """Get a specific account by ID."""
    result = await db.execute(
        select(Account).where(Account.id == account_id)
    )
    account = result.scalar_one_or_none()
    
    if not account:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Account not found"
        )
    
    return account


@router.post("/", response_model=AccountResponse, status_code=status.HTTP_201_CREATED)
async def create_account(
    account_data: AccountCreate,
    db: AsyncSession = Depends(get_db)
):
    """Create a new email account."""
    # Check if account already exists
    result = await db.execute(
        select(Account).where(Account.email_address == account_data.email_address)
    )
    existing = result.scalar_one_or_none()
    
    if existing:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Account with this email already exists"
        )
    
    # Encrypt password
    encrypted_password = encrypt_password(account_data.password)
    
    # Create account
    account = Account(
        email_address=account_data.email_address,
        imap_host=account_data.imap_host,
        imap_port=account_data.imap_port,
        smtp_host=account_data.smtp_host,
        smtp_port=account_data.smtp_port,
        username=account_data.username,
        encrypted_password=encrypted_password,
        is_active=True,
        ssl_verify=account_data.ssl_verify,
        connection_timeout=account_data.connection_timeout
    )
    
    db.add(account)
    await db.commit()
    await db.refresh(account)
    
    return account


@router.put("/{account_id}", response_model=AccountResponse)
async def update_account(
    account_id: int,
    account_data: AccountUpdate,
    db: AsyncSession = Depends(get_db)
):
    """Update an existing account."""
    result = await db.execute(
        select(Account).where(Account.id == account_id)
    )
    account = result.scalar_one_or_none()
    
    if not account:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Account not found"
        )
    
    # Update fields
    update_data = account_data.model_dump(exclude_unset=True)
    print(f"DEBUG: Received update for {account_id}: {update_data}")
    
    # Encrypt password if provided
    if 'password' in update_data:
        update_data['encrypted_password'] = encrypt_password(update_data.pop('password'))
    
    for field, value in update_data.items():
        setattr(account, field, value)
    
    await db.commit()
    await db.refresh(account)
    
    return account


@router.delete("/{account_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_account(account_id: int, db: AsyncSession = Depends(get_db)):
    """Delete an account."""
    result = await db.execute(
        select(Account).where(Account.id == account_id)
    )
    account = result.scalar_one_or_none()
    
    if not account:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Account not found"
        )
    
    await db.delete(account)
    await db.commit()
    
    return None


@router.post("/{account_id}/test")
async def test_connection(account_id: int, db: AsyncSession = Depends(get_db)):
    """Test IMAP connection for an account with detailed diagnostics."""
    result = await db.execute(
        select(Account).where(Account.id == account_id)
    )
    account = result.scalar_one_or_none()
    
    if not account:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Account not found"
        )
    
    # Decrypt password
    try:
        password = decrypt_password(account.encrypted_password)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Failed to decrypt password: {str(e)}"
        )
    
    # Test connection with detailed error handling
    imap = IMAPService(account, password)
    
    try:
        success = imap.connect()
        
        if success:
            # Get additional information
            folders = imap.list_folders()
            imap.disconnect()
            
            return {
                "status": "success",
                "message": "Connection successful",
                "details": {
                    "host": account.imap_host,
                    "port": account.imap_port,
                    "ssl_verify": account.ssl_verify,
                    "folders_found": len(folders),
                    "sample_folders": folders[:5] if folders else []
                }
            }
        else:
            return {
                "status": "error",
                "message": "Connection failed",
                "details": {
                    "host": account.imap_host,
                    "port": account.imap_port,
                    "error": "Unknown connection error"
                }
            }
    
    except Exception as e:
        from app.services.imap_service import IMAPConnectionError, IMAPAuthenticationError
        
        error_type = type(e).__name__
        error_message = str(e)
        
        # Provide helpful suggestions based on error type
        suggestions = []
        
        if isinstance(e, IMAPAuthenticationError):
            suggestions = [
                "Verify your username and password are correct",
                "For Gmail: Enable 'Less secure app access' or use an App Password",
                "For Outlook: Use an App Password if 2FA is enabled",
                "Check if IMAP is enabled in your email account settings"
            ]
        elif isinstance(e, IMAPConnectionError):
            if "timeout" in error_message.lower():
                suggestions = [
                    "Check your internet connection",
                    "Verify the IMAP server is not blocked by firewall",
                    "Try increasing the connection timeout in account settings"
                ]
            elif "ssl" in error_message.lower():
                suggestions = [
                    "Try disabling SSL verification in account settings",
                    "Verify the IMAP host and port are correct",
                    "Check if the server uses a self-signed certificate"
                ]
            else:
                suggestions = [
                    "Verify the IMAP host and port are correct",
                    "Check if IMAP is enabled on the server",
                    "Ensure your firewall allows IMAP connections"
                ]
        
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail={
                "status": "error",
                "error_type": error_type,
                "message": error_message,
                "suggestions": suggestions
            }
        )

