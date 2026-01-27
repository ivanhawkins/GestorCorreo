from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from typing import List

from app.database import get_db
from app.models import Category
from app.schemas import CategoryCreate, CategoryUpdate, CategoryResponse

router = APIRouter(prefix="/api/categories", tags=["categories"])

@router.get("/", response_model=List[CategoryResponse])
async def list_categories(db: AsyncSession = Depends(get_db)):
    """List all categories."""
    result = await db.execute(select(Category).order_by(Category.id))
    return result.scalars().all()

@router.post("/", response_model=CategoryResponse, status_code=status.HTTP_201_CREATED)
async def create_category(category: CategoryCreate, db: AsyncSession = Depends(get_db)):
    """Create a new category."""
    # Check if key exists
    result = await db.execute(select(Category).where(Category.key == category.key))
    if result.scalar_one_or_none():
        raise HTTPException(status_code=400, detail="Category key already exists")
        
    db_cat = Category(**category.model_dump())
    db.add(db_cat)
    await db.commit()
    await db.refresh(db_cat)
    return db_cat

@router.put("/{category_id}", response_model=CategoryResponse)
async def update_category(category_id: int, category: CategoryUpdate, db: AsyncSession = Depends(get_db)):
    """Update a category."""
    result = await db.execute(select(Category).where(Category.id == category_id))
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
async def delete_category(category_id: int, db: AsyncSession = Depends(get_db)):
    """Delete a category."""
    result = await db.execute(select(Category).where(Category.id == category_id))
    db_cat = result.scalar_one_or_none()
    
    if not db_cat:
        raise HTTPException(status_code=404, detail="Category not found")
        
    if db_cat.is_system:
        raise HTTPException(status_code=400, detail="Cannot delete system categories")
        
    await db.delete(db_cat)
    await db.commit()
    return None
