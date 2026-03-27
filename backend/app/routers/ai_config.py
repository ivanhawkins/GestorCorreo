"""
Router for AI configuration endpoints.
Allows admins to configure remote AI API and select classification models.
"""
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from typing import Optional
from pydantic import BaseModel

from app.database import get_db
from app.models import AIConfig, User
from app.dependencies import get_current_user


router = APIRouter()


class AIConfigUpdate(BaseModel):
    """AI configuration update request."""
    api_url: str
    api_key: str
    primary_model: str
    secondary_model: str


class AIConfigResponse(BaseModel):
    """AI configuration response (without API key)."""
    api_url: str
    primary_model: str
    secondary_model: str


@router.get("/", response_model=AIConfigResponse)
async def get_ai_config(
    db: AsyncSession = Depends(get_db),
    current_user: User = Depends(get_current_user)
):
    """
    Get current AI configuration.
    Returns config without API key for security.
    """
    result = await db.execute(select(AIConfig).limit(1))
    config = result.scalar_one_or_none()
    
    if not config:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="AI configuration not found"
        )
    
    return AIConfigResponse(
        api_url=config.api_url,
        primary_model=config.primary_model,
        secondary_model=config.secondary_model
    )



@router.put("/")
async def update_ai_config(
    config_update: AIConfigUpdate,
    db: AsyncSession = Depends(get_db),
    current_user: User = Depends(get_current_user)
):
    """
    Update AI configuration.
    Admin only.
    If api_key is empty, existing key is preserved.
    """
    if not current_user.is_admin:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Admin access required"
        )
    
    result = await db.execute(select(AIConfig).limit(1))
    config = result.scalar_one_or_none()
    
    if not config:
        # Create new config - require API key for new configs
        if not config_update.api_key or not config_update.api_key.strip():
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="API key is required for new configuration"
            )
        config = AIConfig(
            api_url=config_update.api_url,
            api_key=config_update.api_key,
            primary_model=config_update.primary_model,
            secondary_model=config_update.secondary_model
        )
        db.add(config)
    else:
        # Update existing config
        config.api_url = config_update.api_url
        config.primary_model = config_update.primary_model
        config.secondary_model = config_update.secondary_model
        
        # Only update API key if a new one is provided (not empty)
        if config_update.api_key and config_update.api_key.strip():
            config.api_key = config_update.api_key
        # Otherwise, keep existing API key
    
    await db.commit()
    await db.refresh(config)
    
    return {
        "message": "AI configuration updated successfully",
        "api_url": config.api_url,
        "primary_model": config.primary_model,
        "secondary_model": config.secondary_model
    }



@router.get("/models")
async def get_available_models(
    db: AsyncSession = Depends(get_db),
    current_user: User = Depends(get_current_user)
):
    """
    Get available models from Remote AI API.
    Uses current configuration to fetch model list.
    """
    if not current_user.is_admin:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Admin access required"
        )
    
    # Load config
    result = await db.execute(select(AIConfig).limit(1))
    config = result.scalar_one_or_none()
    
    if not config:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="AI configuration not found. Please configure API first."
        )
    
    # Create client
    from app.services.ai_service import RemoteAIClient
    
    client = RemoteAIClient(config.api_url, config.api_key)
    
    try:
        models = await client.list_models()
        return {"models": models}
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Failed to fetch models: {str(e)}"
        )
