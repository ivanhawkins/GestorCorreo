"""
Router for whitelist management.
"""
from typing import Annotated, List
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from pydantic import BaseModel

from app.database import get_db
from app.models import ServiceWhitelist, User
from app.dependencies import get_current_user


router = APIRouter()


class WhitelistCreate(BaseModel):
    """Schema for creating whitelist entry."""
    domain_pattern: str
    description: str = ""


class WhitelistResponse(BaseModel):
    """Schema for whitelist response."""
    id: int
    domain_pattern: str
    description: str
    is_active: bool
    
    class Config:
        from_attributes = True


@router.get("/", response_model=List[WhitelistResponse])
async def list_whitelist(
    current_user: Annotated[User, Depends(get_current_user)],
    db: AsyncSession = Depends(get_db)
):
    """List all whitelist entries for current user."""
    result = await db.execute(
        select(ServiceWhitelist).where(ServiceWhitelist.user_id == current_user.id)
    )
    entries = result.scalars().all()
    return entries


@router.post("/", response_model=WhitelistResponse, status_code=status.HTTP_201_CREATED)
async def create_whitelist_entry(
    entry_data: WhitelistCreate,
    current_user: Annotated[User, Depends(get_current_user)],
    db: AsyncSession = Depends(get_db)
):
    """Add domain to whitelist."""
    # Check if already exists for user
    result = await db.execute(
        select(ServiceWhitelist).where(
            ServiceWhitelist.domain_pattern == entry_data.domain_pattern,
            ServiceWhitelist.user_id == current_user.id
        )
    )
    existing = result.scalar_one_or_none()
    
    if existing:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Domain pattern already in whitelist"
        )
    
    entry = ServiceWhitelist(
        domain_pattern=entry_data.domain_pattern,
        description=entry_data.description,
        is_active=True,
        user_id=current_user.id
    )
    
    db.add(entry)
    await db.commit()
    await db.refresh(entry)
    
    return entry


@router.delete("/{entry_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_whitelist_entry(
    entry_id: int,
    current_user: Annotated[User, Depends(get_current_user)],
    db: AsyncSession = Depends(get_db)
):
    """Remove domain from whitelist."""
    result = await db.execute(
        select(ServiceWhitelist).where(
            ServiceWhitelist.id == entry_id,
            ServiceWhitelist.user_id == current_user.id
        )
    )
    entry = result.scalar_one_or_none()
    
    if not entry:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Whitelist entry not found"
        )
    
    await db.delete(entry)
    await db.commit()
    
    return None


@router.patch("/{entry_id}/toggle")
async def toggle_whitelist_entry(
    entry_id: int,
    current_user: Annotated[User, Depends(get_current_user)],
    db: AsyncSession = Depends(get_db)
):
    """Toggle active status of whitelist entry."""
    result = await db.execute(
        select(ServiceWhitelist).where(
            ServiceWhitelist.id == entry_id,
            ServiceWhitelist.user_id == current_user.id
        )
    )
    entry = result.scalar_one_or_none()
    
    if not entry:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Whitelist entry not found"
        )
    
    entry.is_active = not entry.is_active
    await db.commit()
    await db.refresh(entry)
    
    return {
        "id": entry.id,
        "domain_pattern": entry.domain_pattern,
        "is_active": entry.is_active
    }
