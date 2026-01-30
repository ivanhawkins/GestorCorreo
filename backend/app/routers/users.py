from typing import Annotated, List
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func
from app.database import get_db
from app.models import User, Account
from app.schemas import UserCreate, UserResponse, UserPasswordUpdate
from app.auth import get_password_hash
from app.dependencies import get_current_admin_user

router = APIRouter()

@router.post("/", response_model=UserResponse)
async def create_user(
    user: UserCreate,
    current_user: Annotated[User, Depends(get_current_admin_user)],
    db: Annotated[AsyncSession, Depends(get_db)]
):
    # Check if user exists
    result = await db.execute(select(User).where(User.username == user.username))
    if result.scalar_one_or_none():
        raise HTTPException(status_code=400, detail="Username already registered")
    
    hashed_password = get_password_hash(user.password)
    db_user = User(
        username=user.username,
        password_hash=hashed_password,
        is_admin=user.is_admin
    )
    
    db.add(db_user)
    await db.commit()
    await db.refresh(db_user)
    return db_user

@router.get("/", response_model=List[UserResponse])
async def list_users(
    current_user: Annotated[User, Depends(get_current_admin_user)],
    db: Annotated[AsyncSession, Depends(get_db)],
    deleted: bool = False,
):
    query = select(User)
    if deleted:
        query = query.where(User.deleted_at.isnot(None))
    else:
        query = query.where(User.deleted_at.is_(None))
        
    result = await db.execute(query)
    users = result.scalars().all()
    
    # Calculate storage usage for each user
    user_responses = []
    for user in users:
        # Sum storage of all accounts for this user
        # We can do this with a query or relationship if loaded
        q_storage = select(func.sum(Account.mailbox_storage_bytes)).where(Account.user_id == user.id)
        res_storage = await db.execute(q_storage)
        usage = res_storage.scalar() or 0
        
        user_resp = UserResponse.model_validate(user)
        user_resp.mailbox_usage_bytes = usage
        user_responses.append(user_resp)
        
    return user_responses

@router.delete("/{user_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_user(
    user_id: int,
    current_user: Annotated[User, Depends(get_current_admin_user)],
    db: Annotated[AsyncSession, Depends(get_db)],
    permanent: bool = False,
):
    result = await db.execute(select(User).where(User.id == user_id))
    user = result.scalar_one_or_none()
    
    if not user:
        raise HTTPException(status_code=404, detail="User not found")
        
    if permanent:
        # Restriction removed: Admin can permanently delete anytime
        await db.delete(user)
    else:
        from datetime import datetime
        user.is_active = False
        user.deleted_at = datetime.now()
        
    await db.commit()

@router.post("/{user_id}/restore", response_model=UserResponse)
async def restore_user(
    user_id: int,
    current_user: Annotated[User, Depends(get_current_admin_user)],
    db: Annotated[AsyncSession, Depends(get_db)]
):
    result = await db.execute(select(User).where(User.id == user_id))
    user = result.scalar_one_or_none()
    
    if not user:
        raise HTTPException(status_code=404, detail="User not found")
        
    user.is_active = True
    user.deleted_at = None
    await db.commit()
    await db.refresh(user)
    return user


@router.put("/{user_id}/password", status_code=status.HTTP_200_OK)
async def update_password(
    user_id: int,
    password_data: UserPasswordUpdate,
    current_user: Annotated[User, Depends(get_current_admin_user)],
    db: Annotated[AsyncSession, Depends(get_db)]
):
    
    result = await db.execute(select(User).where(User.id == user_id))
    user = result.scalar_one_or_none()
    
    if not user:
        raise HTTPException(status_code=404, detail="User not found")
        
    hashed_password = get_password_hash(password_data.password)
    user.password_hash = hashed_password
    
    await db.commit()
    return {"message": "Password updated successfully"}
