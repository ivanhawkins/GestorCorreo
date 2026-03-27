"""
Router for attachment endpoints.
"""
from fastapi import APIRouter, Depends, HTTPException, status
from fastapi.responses import FileResponse
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from pathlib import Path

from app.database import get_db
from app.models import Attachment, Message, User
from app.dependencies import get_current_active_user


router = APIRouter()


@router.get("/{attachment_id}")
async def download_attachment(
    attachment_id: int,
    db: AsyncSession = Depends(get_db),
    current_user: User = Depends(get_current_active_user)
):
    """Download an attachment file."""
    result = await db.execute(
        select(Attachment).where(Attachment.id == attachment_id)
    )
    attachment = result.scalar_one_or_none()
    
    if not attachment:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Attachment not found"
        )
    
    if not attachment.local_path:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Attachment file path not registered"
        )

    # Construct full path
    data_dir = Path(__file__).parent.parent.parent.parent / "data"
    file_path = data_dir / attachment.local_path
    
    if not file_path.exists() or file_path.is_dir():
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Attachment file not found on disk"
        )
    
    return FileResponse(
        path=str(file_path),
        filename=attachment.filename,
        media_type=attachment.mime_type or "application/octet-stream"
    )


@router.get("/message/{message_id}")
async def list_message_attachments(
    message_id: str,
    db: AsyncSession = Depends(get_db),
    current_user: User = Depends(get_current_active_user)
):
    """List all attachments for a message."""
    result = await db.execute(
        select(Attachment).where(Attachment.message_id == message_id)
    )
    attachments = result.scalars().all()
    
    return [
        {
            "id": att.id,
            "filename": att.filename,
            "mime_type": att.mime_type,
            "size_bytes": att.size_bytes
        }
        for att in attachments
    ]
