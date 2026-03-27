from fastapi import APIRouter, Depends
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from app.database import get_db
from app.models import Category
from app.services.ai_service import build_classification_prompt, REVIEW_PROMPT, REPLY_PROMPT

router = APIRouter()

@router.get("/prompts")
async def get_system_prompts(db: AsyncSession = Depends(get_db)):
    """Get the current default system prompts."""
    # Fetch categories for the classification prompt
    result = await db.execute(select(Category))
    categories = [{"key": c.key, "ai_instruction": c.ai_instruction} for c in result.scalars().all()]
    
    classification_prompt = build_classification_prompt(categories)
    
    return {
        "classification_prompt": classification_prompt,
        "review_prompt": REVIEW_PROMPT,
        "reply_prompt": REPLY_PROMPT
    }
