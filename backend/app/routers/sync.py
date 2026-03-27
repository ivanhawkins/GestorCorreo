"""
Router for sync endpoints.
"""
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select

from app.database import get_db
from app.models import Account, AuditLog, User
from app.schemas import SyncRequest, SyncResponse
from app.utils.security import decrypt_password
from app.services.imap_service import sync_account_messages
from app.dependencies import get_current_active_user
import json
import logging
from datetime import datetime

logger = logging.getLogger(__name__)


router = APIRouter()


from fastapi.responses import StreamingResponse
import asyncio

@router.post("/start", response_model=SyncResponse)
async def start_sync(
    sync_request: SyncRequest,
    db: AsyncSession = Depends(get_db),
    current_user: User = Depends(get_current_active_user)
):
    """
    Start synchronization for an account (Legacy non-streaming).
    """
    # This endpoint now consumes the stream internally to maintain backward compatibility
    # but the frontend should move to /stream for progress updates.
    
    # ... (same setup code as before)
    # Get account
    result = await db.execute(
        select(Account).where(Account.id == sync_request.account_id, Account.user_id == current_user.id)
    )
    account = result.scalar_one_or_none()
    
    if not account:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Account not found")
    
    if not account.is_active:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Account is not active")
    
    # Decrypt password
    try:
        password = decrypt_password(account.encrypted_password)
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail="Failed to decrypt password")
    
    # Consume generator
    sync_result = None
    async for progress in sync_account_messages(account, password, db, sync_request.folder):
        # We just want the final result or error
        if progress.get('status') in ['success', 'error']:
            sync_result = progress
            
    if not sync_result:
        sync_result = {'status': 'error', 'error': 'Sync produced no result'}

    # Auto-classify new messages
    classified_count = 0
    if sync_result.get("status") == "success" and sync_result.get("new_messages", 0) > 0:
        try:
            from app.services.scheduler import run_classification
            
            new_msg_ids = sync_result.get("new_message_ids", [])
            if new_msg_ids:
                classified_count = await run_classification(db, account, new_msg_ids)
        except Exception as e:
            logger.error(f"Auto-classify error in /start: {e}")

    # Audit log
    audit_log = AuditLog(
        action="sync",
        payload=json.dumps({
            "account_id": account.id,
            "folder": sync_request.folder,
            "auto_classify": sync_request.auto_classify,
            "result": sync_result,
            "classified_count": classified_count
        }),
        status="success" if sync_result["status"] == "success" else "error",
        error_message=sync_result.get("error")
    )
    db.add(audit_log)
    await db.commit()
    
    # Return result
    if sync_result["status"] == "error":
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=sync_result.get("error", "Sync failed")
        )
    
    return SyncResponse(
        **sync_result,
        classified_count=classified_count
    )


@router.post("/stream")
async def stream_sync(
    sync_request: SyncRequest,
    db: AsyncSession = Depends(get_db),
    current_user: User = Depends(get_current_active_user)
):
    """
    Stream synchronization progress for an account using SSE.
    """
    # Get account
    result = await db.execute(
        select(Account).where(Account.id == sync_request.account_id, Account.user_id == current_user.id)
    )
    account = result.scalar_one_or_none()
    
    if not account:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Account not found")
        
    try:
        password = decrypt_password(account.encrypted_password)
    except Exception:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail="Failed to decrypt password")

    async def event_generator():
        # Create a new database session for the generator
        from app.database import AsyncSessionLocal
        async with AsyncSessionLocal() as db:
            try:
                classified_count = 0
                sync_final_result = None
            
                # 1. Sync Phase
                async for progress in sync_account_messages(account, password, db, sync_request.folder):
                    yield f"data: {json.dumps(progress)}\n\n"
                    if progress.get('status') in ['success', 'error']:
                        sync_final_result = progress

                # 2. Auto-Classify Phase (Always classify automatically)
                if sync_final_result and sync_final_result.get("status") == "success" and sync_final_result.get("new_messages", 0) > 0:
                    yield f"data: {json.dumps({'status': 'classifying', 'message': 'Asignando categorias a los nuevos mensajes...'})}\n\n"
                    
                    try:
                        from app.services.scheduler import run_classification
                        
                        new_msg_ids = sync_final_result.get("new_message_ids", [])
                        if new_msg_ids:
                            classified_count = await run_classification(db, account, new_msg_ids)
                            yield f"data: {json.dumps({'status': 'classifying_progress', 'current': classified_count, 'total': len(new_msg_ids), 'message': f'Classified {classified_count}/{len(new_msg_ids)}'})}\n\n"
                    except Exception as e:
                        logger.error(f"Auto-classify error: {e}")
                        yield f"data: {json.dumps({'status': 'warning', 'message': 'Auto-classification failed'})}\n\n"

                # 3. Log and Finish
                if sync_final_result:
                     audit_log = AuditLog(
                        action="sync",
                        payload=json.dumps({
                            "account_id": account.id,
                            "folder": sync_request.folder,
                            "auto_classify": sync_request.auto_classify,
                            "result": sync_final_result,
                            "classified_count": classified_count
                        }),
                        status="success" if sync_final_result["status"] == "success" else "error",
                        error_message=sync_final_result.get("error")
                    )
                     db.add(audit_log)
                     await db.commit()
                
                # Final event with full stats
                final_payload = {
                    'status': 'complete',
                    'sync_result': sync_final_result,
                    'classified_count': classified_count,
                    'message': 'Sync completed'
                }
                yield f"data: {json.dumps(final_payload)}\n\n"
            except Exception as e:
                # Catch any unhandled exception and send error to client
                logger.exception(f"Unexpected error in sync stream: {e}")
                error_payload = {
                    'status': 'error',
                    'error': str(e),
                    'message': 'Sync failed due to unexpected error'
                }
                yield f"data: {json.dumps(error_payload)}\n\n"

    return StreamingResponse(event_generator(), media_type="text/event-stream")


@router.get("/status")
async def get_sync_status(db: AsyncSession = Depends(get_db)):
    """Get status of recent sync operations."""
    result = await db.execute(
        select(AuditLog)
        .where(AuditLog.action == "sync")
        .order_by(AuditLog.timestamp.desc())
        .limit(10)
    )
    logs = result.scalars().all()
    
    return {
        "recent_syncs": [
            {
                "timestamp": log.timestamp,
                "status": log.status,
                "payload": json.loads(log.payload) if log.payload else {},
                "error": log.error_message
            }
            for log in logs
        ]
    }


@router.post("/resync-bodies")
async def resync_message_bodies(
    account_id: int,
    db: AsyncSession = Depends(get_db),
    current_user: User = Depends(get_current_active_user)
):
    """
    Re-download body content for existing messages that have no body stored.
    Useful when messages were synced without body content.
    """
    from app.services.imap_service import IMAPService
    from app.utils.security import decrypt_password

    result = await db.execute(
        select(Account).where(Account.id == account_id, Account.user_id == current_user.id)
    )
    account = result.scalar_one_or_none()

    if not account:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Account not found")

    try:
        password = decrypt_password(account.encrypted_password)
    except Exception:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail="Failed to decrypt password")

    # Get messages without body
    from app.models import Message
    msg_result = await db.execute(
        select(Message)
        .where(Message.account_id == account_id)
        .where(
            (Message.body_text == None) & (Message.body_html == None)
        )
        .order_by(Message.folder)  # Order by folder to optimize IMAP selections
        .limit(200)  # Process max 200 at a time
    )
    messages = msg_result.scalars().all()

    if not messages:
        return {"updated": 0, "message": "No messages without body found"}

    imap = IMAPService(account, password)
    try:
        connected = await imap.connect()
        if not connected:
            raise HTTPException(status_code=500, detail="Failed to connect to IMAP server")

        current_folder = None

        for message in messages:
            try:
                target_folder = message.folder or "INBOX"
                if current_folder != target_folder:
                    await imap.select_folder(target_folder)
                    current_folder = target_folder
                    
                body_data = await imap.fetch_full_message_body(message.imap_uid)
                if body_data:
                    body_text = body_data.get('body_text') or ''
                    body_html = body_data.get('body_html') or ''
                    # Only update if we actually got content
                    if body_text or body_html:
                        message.body_text = body_text if body_text else None
                        message.body_html = body_html if body_html else None
                        message.has_attachments = len(body_data.get('attachments', [])) > 0
                        # Also update snippet from body
                        if body_text and not message.snippet:
                            message.snippet = body_text[:200]
                        updated_count += 1
                else:
                    failed_count += 1
            except Exception as e:
                logger.error(f"Error re-syncing body for message {message.id}: {e}")
                failed_count += 1

        await db.commit()
        await imap.disconnect()

        return {
            "updated": updated_count,
            "failed": failed_count,
            "total": len(messages),
            "message": f"Re-synced {updated_count} message bodies"
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error in resync-bodies: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/resync-attachments")
async def resync_message_attachments(
    account_id: int,
    db: AsyncSession = Depends(get_db),
    current_user: User = Depends(get_current_active_user)
):
    """
    Re-download and save attachments for messages that have has_attachments=True
    but no entries in the attachments table.
    """
    from app.services.imap_service import IMAPService
    from app.utils.security import decrypt_password
    from app.models import Message, Attachment
    import uuid
    from pathlib import Path

    result = await db.execute(
        select(Account).where(Account.id == account_id, Account.user_id == current_user.id)
    )
    account = result.scalar_one_or_none()

    if not account:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Account not found")

    try:
        password = decrypt_password(account.encrypted_password)
    except Exception:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail="Failed to decrypt password")

    # Get messages that claim to have attachments but have none in the DB
    msg_result = await db.execute(
        select(Message)
        .outerjoin(Attachment, Message.id == Attachment.message_id)
        .where(Message.account_id == account_id)
        .where(Message.has_attachments == True)
        .where(Attachment.id == None)
        .order_by(Message.folder)  # Optimize IMAP folder switching
        .limit(100)
    )
    messages = msg_result.scalars().all()

    if not messages:
        return {"updated": 0, "failed": 0, "total": 0, "message": "No messages missing attachments found"}

    imap = IMAPService(account, password)
    try:
        connected = await imap.connect()
        if not connected:
            raise HTTPException(status_code=500, detail="Failed to connect to IMAP server")

        updated_count = 0
        failed_count = 0
        current_folder = None

        for message in messages:
            try:
                target_folder = message.folder or "INBOX"
                if current_folder != target_folder:
                    await imap.select_folder(target_folder)
                    current_folder = target_folder
                    
                body_data = await imap.fetch_full_message_body(message.imap_uid)
                if body_data and body_data.get('attachments'):
                    for att_data in body_data['attachments']:
                        attachment = Attachment(
                            message_id=message.id,
                            filename=att_data.get('filename', 'unknown'),
                            mime_type=att_data.get('mime_type'),
                            size_bytes=att_data.get('size_bytes', 0),
                            local_path=att_data.get('local_path', '')
                        )
                        db.add(attachment)
                    updated_count += 1
                else:
                    # No attachments found despite the flag — correct it
                    message.has_attachments = False
                    failed_count += 1
            except Exception as e:
                logger.error(f"Error re-syncing attachments for message {message.id}: {e}")
                failed_count += 1

        await db.commit()
        await imap.disconnect()

        return {
            "updated": updated_count,
            "failed": failed_count,
            "total": len(messages),
            "message": f"Re-synced attachments for {updated_count} messages"
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error in resync-attachments: {e}")
        raise HTTPException(status_code=500, detail=str(e))


