"""
Router for message endpoints.
"""
from fastapi import APIRouter, Depends, HTTPException, status, Query
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, or_
from sqlalchemy.orm import outerjoin
from typing import List, Optional

from app.database import get_db
from app.models import Message, Classification, Account, Attachment
from app.schemas import MessageResponse, MessageDetailResponse, MessageUpdate
from sqlalchemy import func


router = APIRouter()


@router.get("/")
async def list_messages(
    account_id: Optional[int] = Query(None),
    folder: Optional[str] = Query(None),
    classification_label: Optional[str] = Query(None),
    search: Optional[str] = Query(None),
    from_email: Optional[str] = Query(None),
    is_starred: Optional[bool] = Query(None),
    has_attachments: Optional[bool] = Query(None),
    date_from: Optional[str] = Query(None),
    date_to: Optional[str] = Query(None),
    limit: int = Query(50, le=200),
    offset: int = Query(0),
    db: AsyncSession = Depends(get_db)
):
    """
    List messages with optional filters.
    
    - **account_id**: Filter by account
    - **folder**: Filter by folder (not implemented yet)
    - **classification_label**: Filter by classification label (Interesantes, SPAM, EnCopia, Servicios)
    - **search**: Search in subject and from_email
    - **limit**: Max number of results (default 50, max 200)
    - **offset**: Pagination offset
    """
    # Build query with left join to classifications
    query = select(Message, Classification.final_label).outerjoin(
        Classification, Message.id == Classification.message_id
    )
    
    # Apply filters
    if account_id:
        query = query.where(Message.account_id == account_id)

    # Handle 'Deleted' folder or exclusion
    if folder == 'Deleted':
        query = query.where(Classification.final_label == 'Deleted')
    else:
        # Default behavior: Exclude 'Deleted' messages from other views
        # Includes messages with NO classification OR classification != 'Deleted'
        query = query.where(
            or_(
                Classification.final_label != 'Deleted',
                Classification.final_label.is_(None)
            )
        )
    
    if classification_label:
        if classification_label == 'INBOX':
            # Unclassified messages (and implicitly not Deleted due to above check)
            query = query.where(Classification.final_label.is_(None))
        else:
            query = query.where(Classification.final_label == classification_label)
    
    if search:
        search_pattern = f"%{search}%"
        query = query.where(
            or_(
                Message.subject.ilike(search_pattern),
                Message.from_email.ilike(search_pattern),
                Message.from_name.ilike(search_pattern),
                Message.body_text.ilike(search_pattern)
            )
        )
    
    if from_email:
        query = query.where(Message.from_email.ilike(f"%{from_email}%"))
    
    if is_starred is not None:
        query = query.where(Message.is_starred == is_starred)
    
    if has_attachments is not None:
        query = query.where(Message.has_attachments == has_attachments)
    
    if date_from:
        query = query.where(Message.date >= date_from)
    
    if date_to:
        query = query.where(Message.date <= date_to)
    
    # Order by date descending
    query = query.order_by(Message.date.desc())
    
    # Apply pagination
    query = query.limit(limit).offset(offset)
    
    result = await db.execute(query)
    rows = result.all()
    
    # Build response with classification labels
    messages = []
    for message, classification_label in rows:
        message_dict = {
            "id": message.id,
            "account_id": message.account_id,
            "from_name": message.from_name,
            "from_email": message.from_email,
            "subject": message.subject,
            "date": message.date,
            "snippet": message.snippet,
            "is_read": message.is_read,
            "is_starred": message.is_starred,
            "has_attachments": message.has_attachments,
            "classification_label": classification_label
        }
        messages.append(message_dict)
    
    return messages


@router.patch("/bulk/read")
async def bulk_mark_as_read(
    account_id: int = Query(...),
    classification_label: Optional[str] = Query(None),
    is_read: bool = Query(True),
    db: AsyncSession = Depends(get_db)
):
    """
    Mark multiple messages as read/unread.
    
    - **account_id**: Account ID to filter messages
    - **classification_label**: Optional classification label filter
    - **is_read**: True to mark as read, False to mark as unread
    """
    # Build query
    query = select(Message).where(Message.account_id == account_id)
    
    if classification_label:
        # Join with classifications to filter
        query = query.join(
            Classification, Message.id == Classification.message_id
        ).where(Classification.final_label == classification_label)
    
    result = await db.execute(query)
    messages = result.scalars().all()
    
    count = 0
    for message in messages:
        if message.is_read != is_read:
            message.is_read = is_read
            count += 1
    
    await db.commit()
    
    return {"updated": count, "total": len(messages)}


@router.delete("/bulk", status_code=status.HTTP_204_NO_CONTENT)
async def empty_folder(
    account_id: int = Query(...),
    folder: Optional[str] = Query(None),
    classification_label: Optional[str] = Query(None),
    db: AsyncSession = Depends(get_db)
):
    """
    Empty a specific folder or classification (move to Deleted or permanently delete if already in Deleted).
    """
    # Build query for messages to delete
    query = select(Message).where(Message.account_id == account_id)
    
    if folder == 'Deleted':
        # Permanently delete messages in Deleted folder
        # Determine target messages
        query = query.join(
            Classification, Message.id == Classification.message_id
        ).where(Classification.final_label == 'Deleted')
        
        # Execute delete
        result = await db.execute(query)
        messages = result.scalars().all()
        
        for message in messages:
            # Decrement storage
            msg_size = (len(message.body_text) if message.body_text else 0) + (len(message.body_html) if message.body_html else 0)
            # Add attachment sizes
            # We need to fetch attachments or sum them up
            # For simplicity in this valid "bulk" context, we might want to do a sum query first for performance
            # But iterating is fine for reasonable numbers or we do a separate aggregate query
            
            # Simple approach for now per message (can be optimized later)
            result_att = await db.execute(select(func.sum(Attachment.size_bytes)).where(Attachment.message_id == message.id))
            att_size = result_att.scalar() or 0
            
            total_msg_size = msg_size + att_size
            
            # Get account to update
            # Ideally we fetch account once but messages might belong to different accounts if not careful, 
            # though the endpoint has account_id param.
            # We can do a single update at the end if we trust they are all same account.
            # endpoint has account_id, so we can aggregate.
            pass

        # Aggregate size cleanup
        # sum text + html
        q_size = select(
            func.sum(func.length(Message.body_text)), 
            func.sum(func.length(Message.body_html))
        ).where(Message.id.in_([m.id for m in messages]))
        
        res_size = await db.execute(q_size)
        sum_text, sum_html = res_size.one()
        sum_text = sum_text or 0
        sum_html = sum_html or 0
        
        # sum attachments
        q_att_size = select(func.sum(Attachment.size_bytes)).join(Message).where(Message.id.in_([m.id for m in messages]))
        res_att_size = await db.execute(q_att_size)
        sum_att = res_att_size.scalar() or 0
        
        total_deleted_bytes = sum_text + sum_html + sum_att
        
        if total_deleted_bytes > 0:
             # Update account
             acc_result = await db.execute(select(Account).where(Account.id == account_id))
             account = acc_result.scalar_one_or_none()
             if account:
                 if account.mailbox_storage_bytes:
                     account.mailbox_storage_bytes = max(0, account.mailbox_storage_bytes - total_deleted_bytes)
        
        for message in messages:
            await db.delete(message)
            
    else:
        # Move to Deleted (Soft Delete)
        if classification_label:
             query = query.join(
                Classification, Message.id == Classification.message_id
            ).where(Classification.final_label == classification_label)
        elif folder == 'INBOX' or not folder:
             # Inbox means unclassified (or specifically classified as Inbox if we had that)
             query = query.outerjoin(
                Classification, Message.id == Classification.message_id
            ).where(Classification.final_label.is_(None))
        
        result = await db.execute(query)
        messages = result.scalars().all()
        
        for message in messages:
            # Check if classification exists
            cls_result = await db.execute(
                select(Classification).where(Classification.message_id == message.id)
            )
            classification = cls_result.scalar_one_or_none()
            
            if classification:
                classification.final_label = 'Deleted'
                classification.decided_by = 'user_bulk_delete'
            else:
                new_classification = Classification(
                    message_id=message.id,
                    final_label='Deleted',
                    decided_by='user_bulk_delete'
                )
                db.add(new_classification)

    await db.commit()
    return None


@router.get("/{message_id}")
async def get_message(
    message_id: str,
    db: AsyncSession = Depends(get_db)
):
    """Get a specific message by ID with full details including body and attachments."""
    result = await db.execute(
        select(Message, Classification.final_label).outerjoin(
            Classification, Message.id == Classification.message_id
        ).where(Message.id == message_id)
    )
    row = result.one_or_none()
    
    if not row:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Message not found"
        )
    
    message, classification_label = row
    
    # Fetch attachments for this message
    att_result = await db.execute(
        select(Attachment).where(Attachment.message_id == message_id)
    )
    attachments = att_result.scalars().all()
    
    message_dict = {
        "id": message.id,
        "account_id": message.account_id,
        "from_name": message.from_name,
        "from_email": message.from_email,
        "to_addresses": message.to_addresses,
        "cc_addresses": message.cc_addresses,
        "bcc_addresses": message.bcc_addresses,
        "subject": message.subject,
        "date": message.date,
        "snippet": message.snippet,
        "is_read": message.is_read,
        "is_starred": message.is_starred,
        "has_attachments": message.has_attachments,
        "classification_label": classification_label,
        "body_text": message.body_text,
        "body_html": message.body_html,
        "message_id": message.message_id if hasattr(message, 'message_id') else None,
        "folder": message.folder if hasattr(message, 'folder') else "INBOX",
        "attachments": [
            {
                "id": att.id,
                "filename": att.filename,
                "mime_type": att.mime_type,
                "size_bytes": att.size_bytes
            }
            for att in attachments
        ]
    }
    
    return message_dict


@router.get("/{message_id}/body")
async def get_message_body(
    message_id: str,
    db: AsyncSession = Depends(get_db)
):
    """Get message body (text and HTML) for a specific message."""
    result = await db.execute(
        select(Message).where(Message.id == message_id)
    )
    message = result.scalar_one_or_none()
    
    if not message:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Message not found"
        )
    
    return {
        "body_text": message.body_text,
        "body_html": message.body_html
    }



@router.patch("/{message_id}/read")
async def mark_message_read(
    message_id: str,
    is_read: bool = Query(True),
    db: AsyncSession = Depends(get_db)
):
    """
    Mark a message as read or unread.
    
    - **message_id**: Message ID
    - **is_read**: True to mark as read, False to mark as unread
    """
    result = await db.execute(
        select(Message).where(Message.id == message_id)
    )
    message = result.scalar_one_or_none()
    
    if not message:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Message not found"
        )
    
    message.is_read = is_read
    await db.commit()
    await db.refresh(message)
    
    return {"id": message.id, "is_read": message.is_read}





@router.patch("/{message_id}/star")
async def toggle_star(
    message_id: str,
    is_starred: bool = Query(...),
    db: AsyncSession = Depends(get_db)
):
    """
    Toggle star status on a message.
    
    - **message_id**: Message ID
    - **is_starred**: True to star, False to unstar
    """
    result = await db.execute(
        select(Message).where(Message.id == message_id)
    )
    message = result.scalar_one_or_none()
    
    if not message:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Message not found"
        )
    
    message.is_starred = is_starred
    await db.commit()
    await db.refresh(message)
    
    return {"id": message.id, "is_starred": message.is_starred}




@router.delete("/{message_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_message(
    message_id: str,
    db: AsyncSession = Depends(get_db)
):
    """
    Move message to Deleted folder (by setting label='Deleted').
    
    - **message_id**: Message ID
    """
    # Check if message exists
    result = await db.execute(
        select(Message).where(Message.id == message_id)
    )
    message = result.scalar_one_or_none()
    
    if not message:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Message not found"
        )
    
    # Update classification to 'Deleted'
    # Check if classification exists
    cls_result = await db.execute(
        select(Classification).where(Classification.message_id == message_id)
    )
    classification = cls_result.scalar_one_or_none()
    
    if classification:
        classification.final_label = 'Deleted'
        classification.decided_by = 'user_delete'
    else:
        new_classification = Classification(
            message_id=message_id,
            final_label='Deleted',
            decided_by='user_delete'
        )
        db.add(new_classification)
    
    await db.commit()
    
    return None


@router.put("/{message_id}/classification")
async def update_classification(
    message_id: str,
    label: Optional[str] = Query(None),
    db: AsyncSession = Depends(get_db)
):
    """
    Update details manually.
    - **label**: New classification label (or None to clear)
    """
    # Find existing classification
    result = await db.execute(
        select(Classification).where(Classification.message_id == message_id)
    )
    classification = result.scalar_one_or_none()

    if not label:
        # If label is None, remove classification
        if classification:
            await db.delete(classification)
    else:
        # Update or create
        if classification:
            classification.final_label = label
            classification.decided_by = "manual_user"
        else:
            new_classification = Classification(
                message_id=message_id,
                final_label=label,
                decided_by="manual_user"
            )
            db.add(new_classification)

    await db.commit()
    return {"message_id": message_id, "classification_label": label}
