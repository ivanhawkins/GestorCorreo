from typing import Annotated, List
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select

from app.database import get_db
from app.models import Category, User
from app.schemas import CategoryCreate, CategoryUpdate, CategoryResponse
from app.dependencies import get_current_user

router = APIRouter(tags=["categories"])

@router.get("/", response_model=List[CategoryResponse])
async def list_categories(
    current_user: Annotated[User, Depends(get_current_user)],
    db: AsyncSession = Depends(get_db)
):
    """List all categories for current user."""
    result = await db.execute(select(Category).where(Category.user_id == current_user.id).order_by(Category.id))
    return result.scalars().all()

@router.post("/", response_model=CategoryResponse, status_code=status.HTTP_201_CREATED)
async def create_category(
    category: CategoryCreate, 
    current_user: Annotated[User, Depends(get_current_user)],
    db: AsyncSession = Depends(get_db)
):
    """Create a new category."""
    # Check if key exists for user
    result = await db.execute(
        select(Category).where(
            Category.key == category.key,
            Category.user_id == current_user.id
        )
    )
    if result.scalar_one_or_none():
        raise HTTPException(status_code=400, detail="Category key already exists")
        
    db_cat = Category(**category.model_dump(), user_id=current_user.id)
    db.add(db_cat)
    await db.commit()
    await db.refresh(db_cat)
    return db_cat

@router.put("/{category_id}", response_model=CategoryResponse)
async def update_category(
    category_id: int, 
    category: CategoryUpdate, 
    current_user: Annotated[User, Depends(get_current_user)],
    db: AsyncSession = Depends(get_db)
):
    """Update a category."""
    result = await db.execute(
        select(Category).where(
            Category.id == category_id,
            Category.user_id == current_user.id
        )
    )
    db_cat = result.scalar_one_or_none()
    
    if not db_cat:
        raise HTTPException(status_code=404, detail="Category not found")
        
    update_data = category.model_dump(exclude_unset=True)
    for key, value in update_data.items():
        setattr(db_cat, key, value)
        
    await db.commit()
    await db.refresh(db_cat)
    return db_cat

@router.delete("/{category_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_category(
    category_id: int, 
    current_user: Annotated[User, Depends(get_current_user)],
    db: AsyncSession = Depends(get_db)
):
    """Delete a category."""
    result = await db.execute(
        select(Category).where(
            Category.id == category_id,
            Category.user_id == current_user.id
        )
    )
    db_cat = result.scalar_one_or_none()
    
    if not db_cat:
        raise HTTPException(status_code=404, detail="Category not found")
        
    if db_cat.is_system:
        raise HTTPException(status_code=400, detail="Cannot delete system categories")
        
    await db.delete(db_cat)
    await db.commit()
    return None
