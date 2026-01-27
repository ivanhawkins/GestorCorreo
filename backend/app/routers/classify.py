"""
Router for classification endpoints.
"""
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from typing import List
import json

from app.database import get_db
from app.models import Message, Classification, ServiceWhitelist
from app.services.rules_engine import classify_with_rules_and_ai


router = APIRouter()


@router.post("/{message_id}")
async def classify_message(
    message_id: str,
    db: AsyncSession = Depends(get_db)
):
    """
    Classify a single message using AI + rules.
    
    Returns classification result and saves to database.
    """
    # Get message
    result = await db.execute(
        select(Message).where(Message.id == message_id)
    )
    message = result.scalar_one_or_none()
    
    if not message:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Message not found"
        )
    
    # Check if already classified
    result = await db.execute(
        select(Classification).where(Classification.message_id == message_id)
    )
    existing = result.scalar_one_or_none()
    
    if existing:
        return {
            "message": "Already classified",
            "classification": {
                "final_label": existing.final_label,
                "decided_by": existing.decided_by
            }
        }
    
    # Get whitelist domains
    result = await db.execute(
        select(ServiceWhitelist).where(ServiceWhitelist.is_active == True)
    )
    whitelist_entries = result.scalars().all()
    whitelist_domains = [entry.domain_pattern for entry in whitelist_entries]
    
    # Prepare message data
    message_data = {
        "from_name": message.from_name,
        "from_email": message.from_email,
        "to_addresses": message.to_addresses,
        "cc_addresses": message.cc_addresses,
        "subject": message.subject,
        "date": str(message.date),
        "body_text": message.body_text,
        "snippet": message.snippet
    }
    
    # Classify
    # Classify
    from app.models import Category
    cat_result = await db.execute(select(Category))
    categories_db = cat_result.scalars().all()
    categories = [{"key": c.key, "ai_instruction": c.ai_instruction} for c in categories_db]

    classification_result = await classify_with_rules_and_ai(message_data, whitelist_domains, categories)
    
    if classification_result.get("status") == "error":
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=classification_result.get("error", "Classification failed")
        )
    
    # Save classification
    classification = Classification(
        message_id=message_id,
        gpt_label=classification_result.get("gpt_label"),
        gpt_confidence=classification_result.get("gpt_confidence"),
        gpt_rationale=classification_result.get("gpt_rationale"),
        qwen_label=classification_result.get("qwen_label"),
        qwen_confidence=classification_result.get("qwen_confidence"),
        qwen_rationale=classification_result.get("qwen_rationale"),
        final_label=classification_result["final_label"],
        final_reason=classification_result.get("final_reason"),
        decided_by=classification_result["decided_by"]
    )
    
    db.add(classification)
    await db.commit()
    await db.refresh(classification)
    
    return {
        "message": "Classification successful",
        "classification": {
            "final_label": classification.final_label,
            "decided_by": classification.decided_by,
            "gpt_label": classification.gpt_label,
            "qwen_label": classification.qwen_label
        }
    }


@router.post("/batch")
async def classify_batch(
    message_ids: List[str],
    db: AsyncSession = Depends(get_db)
):
    """
    Classify multiple messages in batch.
    """
    results = []
    
    for message_id in message_ids:
        try:
            result = await classify_message(message_id, db)
            results.append({
                "message_id": message_id,
                "status": "success",
                "result": result
            })
        except Exception as e:
            results.append({
                "message_id": message_id,
                "status": "error",
                "error": str(e)
            })
    
    return {
        "total": len(message_ids),
        "results": results
    }


@router.get("/{message_id}")
async def get_classification(
    message_id: str,
    db: AsyncSession = Depends(get_db)
):
    """Get classification for a message."""
    result = await db.execute(
        select(Classification).where(Classification.message_id == message_id)
    )
    classification = result.scalar_one_or_none()
    
    if not classification:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Classification not found"
        )
    
    return {
        "message_id": classification.message_id,
        "final_label": classification.final_label,
        "decided_by": classification.decided_by,
        "gpt_label": classification.gpt_label,
        "gpt_confidence": classification.gpt_confidence,
        "qwen_label": classification.qwen_label,
        "qwen_confidence": classification.qwen_confidence,
        "decided_at": classification.decided_at
    }
