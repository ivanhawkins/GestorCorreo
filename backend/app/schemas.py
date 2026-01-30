"""
Pydantic schemas for API requests and responses.
"""
from pydantic import BaseModel, EmailStr
from typing import Optional, List
from datetime import datetime


# Account schemas
class AccountCreate(BaseModel):
    """Schema for creating an account."""
    email_address: EmailStr
    imap_host: str
    imap_port: int
    smtp_host: str
    smtp_port: int
    username: str
    password: str  # Will be encrypted before storage
    ssl_verify: bool = True  # Verify SSL certificates
    connection_timeout: int = 30  # Connection timeout in seconds
    auto_classify: bool = False
    auto_sync_interval: int = 0
    custom_classification_prompt: Optional[str] = None
    custom_review_prompt: Optional[str] = None
    owner_profile: Optional[str] = None
    protocol: str = 'imap'

class AccountUpdate(BaseModel):
    """Schema for updating an account."""
    email_address: Optional[EmailStr] = None
    imap_host: Optional[str] = None
    imap_port: Optional[int] = None
    smtp_host: Optional[str] = None
    smtp_port: Optional[int] = None
    username: Optional[str] = None
    password: Optional[str] = None
    is_active: Optional[bool] = None
    ssl_verify: Optional[bool] = None
    connection_timeout: Optional[int] = None
    auto_classify: Optional[bool] = None
    auto_sync_interval: Optional[int] = None
    custom_classification_prompt: Optional[str] = None
    custom_review_prompt: Optional[str] = None
    owner_profile: Optional[str] = None
    protocol: Optional[str] = None  # New

class AccountResponse(BaseModel):
    """Schema for account response."""
    id: int
    email_address: str
    imap_host: str
    imap_port: int
    smtp_host: str
    smtp_port: int
    username: str
    is_active: bool
    ssl_verify: bool
    connection_timeout: int
    auto_classify: bool = False
    auto_sync_interval: int = 0
    custom_classification_prompt: Optional[str] = None
    custom_review_prompt: Optional[str] = None
    owner_profile: Optional[str] = None
    last_sync_error: Optional[str] = None
    
    # New fields
    is_deleted: bool
    protocol: str = 'imap'
    mailbox_storage_bytes: Optional[int] = None
    mailbox_storage_limit: Optional[int] = None
    
    created_at: datetime
    updated_at: datetime
    
    class Config:
        from_attributes = True


# Message schemas
class MessageResponse(BaseModel):
    """Schema for message list response."""
    id: str
    account_id: int
    from_name: Optional[str]
    from_email: str
    subject: Optional[str]
    date: datetime
    snippet: Optional[str]
    is_read: bool
    is_starred: bool
    has_attachments: bool
    classification_label: Optional[str] = None  # Classification category if classified
    
    class Config:
        from_attributes = True


class MessageDetailResponse(MessageResponse):
    """Schema for detailed message response."""
    to_addresses: str
    cc_addresses: str
    bcc_addresses: Optional[str]
    body_text: Optional[str]
    body_html: Optional[str]
    message_id: str


class MessageUpdate(BaseModel):
    """Schema for updating message flags."""
    is_read: Optional[bool] = None
    is_starred: Optional[bool] = None


# Sync schemas
class SyncRequest(BaseModel):
    """Schema for sync request."""
    account_id: int
    folder: str = "INBOX"
    auto_classify: bool = False  # Automatically classify new messages after sync


class SyncResponse(BaseModel):
    """Schema for sync response."""
    status: str
    new_messages: int = 0
    total_messages: int = 0
    classified_count: int = 0  # Number of messages classified (if auto_classify=True)
    error: Optional[str] = None


# Send email schemas
class AttachmentData(BaseModel):
    """Schema for email attachment."""
    filename: str
    content: str  # Base64 encoded content


class SendEmailRequest(BaseModel):
    """Schema for sending email."""
    account_id: int
    to: List[EmailStr]
    cc: Optional[List[EmailStr]] = None
    bcc: Optional[List[EmailStr]] = None
    subject: str
    body_text: Optional[str] = None
    body_html: Optional[str] = None
    attachments: Optional[List[AttachmentData]] = None


class SendEmailResponse(BaseModel):
    """Schema for send email response."""
    status: str
    message: str


# Category schemas
class CategoryCreate(BaseModel):
    """Schema for creating a category."""
    key: str
    name: str
    description: Optional[str] = None
    ai_instruction: str
    icon: Optional[str] = None


class CategoryUpdate(BaseModel):
    """Schema for updating a category."""
    name: Optional[str] = None
    description: Optional[str] = None
    ai_instruction: Optional[str] = None
    icon: Optional[str] = None


class CategoryResponse(BaseModel):
    """Schema for category response."""
    id: int
    key: str
    name: str
    description: Optional[str] = None
    ai_instruction: str
    icon: Optional[str] = None
    is_system: bool
    
    class Config:
        from_attributes = True


# User schemas
class UserCreate(BaseModel):
    """Schema for creating a user."""
    username: str
    password: str
    is_admin: bool = False


class UserPasswordUpdate(BaseModel):
    """Schema for updating user password."""
    password: str


class UserResponse(BaseModel):
    """Schema for user response."""
    id: int
    username: str
    is_active: bool
    is_admin: bool
    created_at: datetime
    mailbox_usage_bytes: Optional[int] = None
    
    class Config:
        from_attributes = True
