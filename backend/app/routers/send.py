"""
Router for sending emails.
"""
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
import base64
import json
import logging

from datetime import datetime
import uuid

from app.database import get_db
from app.models import Account, Message, User
from app.schemas import SendEmailRequest, SendEmailResponse
from app.utils.security import decrypt_password
from app.services.smtp_service import SMTPService
from app.dependencies import get_current_active_user

logger = logging.getLogger(__name__)
router = APIRouter()


@router.post("/", response_model=SendEmailResponse)
async def send_email(
    email_data: SendEmailRequest,
    db: AsyncSession = Depends(get_db),
    current_user: User = Depends(get_current_active_user)
):
    """
    Send an email via SMTP.
    
    - **account_id**: Account to send from
    - **to**: List of recipient emails
    - **cc**: Optional CC recipients
    - **bcc**: Optional BCC recipients
    - **subject**: Email subject
    - **body_text**: Plain text body
    - **body_html**: HTML body
    - **attachments**: Optional list of attachments (base64 encoded)
    """
    # Get account — must belong to the authenticated user
    result = await db.execute(
        select(Account).where(
            Account.id == email_data.account_id,
            Account.user_id == current_user.id
        )
    )
    account = result.scalar_one_or_none()
    
    if not account:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Account not found"
        )
    
    if not account.is_active:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Account is not active"
        )
    
    # Decrypt password
    try:
        password = decrypt_password(account.encrypted_password)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to decrypt password"
        )
    
    # Prepare attachments
    attachments = None
    if email_data.attachments:
        attachments = []
        for att in email_data.attachments:
            try:
                content = base64.b64decode(att.content)
                attachments.append({
                    'filename': att.filename,
                    'content': content
                })
            except Exception as e:
                raise HTTPException(
                    status_code=status.HTTP_400_BAD_REQUEST,
                    detail=f"Invalid attachment data: {str(e)}"
                )
    
    # Send email
    smtp = SMTPService(
        host=account.smtp_host,
        port=account.smtp_port,
        username=account.username,
        password=password
    )
    
    result = await smtp.send_email(
        to_addresses=email_data.to,
        subject=email_data.subject,
        body_text=email_data.body_text,
        body_html=email_data.body_html,
        cc_addresses=email_data.cc,
        bcc_addresses=email_data.bcc,
        attachments=attachments
    )
    
    if result["status"] == "error":
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=result["message"]
        )
    
    # Save to "Enviados" folder
    try:
        msg_id = str(uuid.uuid4())
        message_header_id = f"<{msg_id}@{account.smtp_host}>"

        new_message = Message(
            id=msg_id,
            account_id=account.id,
            imap_uid=0,  # Locally sent, not synced from server
            message_id=message_header_id,
            thread_id=msg_id,
            from_name=account.username,
            from_email=account.email_address,
            to_addresses=json.dumps(email_data.to),
            cc_addresses=json.dumps(email_data.cc) if email_data.cc else json.dumps([]),
            bcc_addresses=json.dumps(email_data.bcc) if email_data.bcc else json.dumps([]),
            subject=email_data.subject,
            body_text=email_data.body_text,
            body_html=email_data.body_html,
            date=datetime.utcnow(),
            is_read=True,
            folder="Enviados",
            has_attachments=bool(attachments),
            snippet=(email_data.body_text[:100] + "...") if email_data.body_text else ""
        )
        
        db.add(new_message)
        await db.commit()
        
    except Exception as e:
        # Log error but don't fail since email was sent
        logger.error(f"Error saving sent message to DB: {e}")

    return SendEmailResponse(**result)
