"""
Router for managing sender rules.
"""
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from typing import List

from app.database import get_db
from app.models import SenderRule
from app.dependencies import get_current_active_user
from pydantic import BaseModel


router = APIRouter()


# Schemas
class SenderRuleCreate(BaseModel):
    sender_email: str
    target_folder: str


class SenderRuleResponse(BaseModel):
    id: int
    user_id: int
    sender_email: str
    target_folder: str
    is_active: bool

    class Config:
        from_attributes = True


@router.post("/", response_model=SenderRuleResponse, status_code=status.HTTP_201_CREATED)
async def create_rule(
    rule_data: SenderRuleCreate,
    current_user = Depends(get_current_active_user),
    db: AsyncSession = Depends(get_db)
):
    """
    Create a new sender rule for the current user.
    """
    # Check if rule already exists
    result = await db.execute(
        select(SenderRule).where(
            SenderRule.user_id == current_user.id,
            SenderRule.sender_email == rule_data.sender_email
        )
    )
    existing_rule = result.scalar_one_or_none()
    
    if existing_rule:
        # Update existing rule
        existing_rule.target_folder = rule_data.target_folder
        existing_rule.is_active = True
        await db.commit()
        await db.refresh(existing_rule)
        return existing_rule
    
    # Create new rule
    new_rule = SenderRule(
        user_id=current_user.id,
        sender_email=rule_data.sender_email,
        target_folder=rule_data.target_folder
    )
    
    db.add(new_rule)
    await db.commit()
    await db.refresh(new_rule)
    
    return new_rule


@router.get("/", response_model=List[SenderRuleResponse])
async def list_rules(
    current_user = Depends(get_current_active_user),
    db: AsyncSession = Depends(get_db)
):
    """
    List all sender rules for the current user.
    """
    result = await db.execute(
        select(SenderRule).where(
            SenderRule.user_id == current_user.id,
            SenderRule.is_active == True
        )
    )
    rules = result.scalars().all()
    return rules


@router.delete("/{rule_id}")
async def delete_rule(
    rule_id: int,
    current_user = Depends(get_current_active_user),
    db: AsyncSession = Depends(get_db)
):
    """
    Delete a sender rule.
    """
    result = await db.execute(
        select(SenderRule).where(
            SenderRule.id == rule_id,
            SenderRule.user_id == current_user.id
        )
    )
    rule = result.scalar_one_or_none()
    
    if not rule:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Rule not found"
        )
    
    await db.delete(rule)
    await db.commit()
    
    return {"success": True}
