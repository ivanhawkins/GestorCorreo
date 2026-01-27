from fastapi import APIRouter, HTTPException, Depends
from pydantic import BaseModel
from typing import Optional, List
from app.services import ai_service

router = APIRouter()

class GenerateReplyRequest(BaseModel):
    original_from_name: Optional[str] = None
    original_from_email: str
    original_subject: Optional[str] = None
    original_body: Optional[str] = None
    user_instruction: Optional[str] = "Responde educadamente"
    owner_profile: Optional[str] = "Eres un asistente profesional."

class GenerateReplyResponse(BaseModel):
    reply_body: str

@router.post("/generate_reply", response_model=GenerateReplyResponse)
async def generate_reply_endpoint(request: GenerateReplyRequest):
    message_data = {
        "from_name": request.original_from_name,
        "from_email": request.original_from_email,
        "subject": request.original_subject,
        "body_text": request.original_body
    }
    
    result = await ai_service.generate_reply(
        message_data=message_data,
        user_instruction=request.user_instruction,
        owner_profile=request.owner_profile
    )
    
    if "error" in result:
        raise HTTPException(status_code=500, detail=result["error"])
        
    return GenerateReplyResponse(reply_body=result.get("reply_body", ""))
