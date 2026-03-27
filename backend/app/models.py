"""
SQLAlchemy models for Mail Manager.
"""
from sqlalchemy import Column, Integer, String, Boolean, DateTime, Text, ForeignKey, Float, UniqueConstraint
from sqlalchemy.sql import func
from app.database import Base


class User(Base):
    """System user."""
    __tablename__ = "users"
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    username = Column(String, unique=True, nullable=False)
    password_hash = Column(String, nullable=False)
    is_active = Column(Boolean, default=True)
    is_admin = Column(Boolean, default=False)
    created_at = Column(DateTime, server_default=func.now())
    deleted_at = Column(DateTime, nullable=True)


class Account(Base):
    """Email account configuration."""
    __tablename__ = "accounts"
    __table_args__ = (UniqueConstraint('user_id', 'email_address', name='uix_account_user_email'),)
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, ForeignKey("users.id"), nullable=False)
    email_address = Column(String, nullable=False)
    imap_host = Column(String, nullable=False)
    imap_port = Column(Integer, nullable=False)
    smtp_host = Column(String, nullable=False)
    smtp_port = Column(Integer, nullable=False)
    username = Column(String, nullable=False)
    encrypted_password = Column(String, nullable=False)
    is_active = Column(Boolean, default=True)
    ssl_verify = Column(Boolean, default=True)  # Verify SSL certificates
    connection_timeout = Column(Integer, default=30)  # Connection timeout in seconds
    auto_classify = Column(Boolean, default=False)  # Automatically classify incoming emails
    auto_sync_interval = Column(Integer, default=0)  # Auto-sync interval in minutes (0 = disabled)
    custom_classification_prompt = Column(Text, nullable=True)  # Custom prompt for classification
    custom_review_prompt = Column(Text, nullable=True)  # Custom prompt for conflict resolution
    owner_profile = Column(Text, nullable=True)  # AI Persona/Profile for this account
    last_sync_error = Column(Text, nullable=True)  # Last sync error message
    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())
    
    # New features
    is_deleted = Column(Boolean, default=False)
    protocol = Column(String, default='imap')  # 'imap' or 'pop3'
    mailbox_storage_bytes = Column(Integer, nullable=True)
    mailbox_storage_limit = Column(Integer, nullable=True)


class Message(Base):
    """Email message."""
    __tablename__ = "messages"
    
    id = Column(String, primary_key=True)  # UUID
    account_id = Column(Integer, ForeignKey("accounts.id", ondelete="CASCADE"), nullable=False)
    imap_uid = Column(Integer, nullable=False)
    message_id = Column(String, nullable=False)  # Email Message-ID header
    thread_id = Column(String)
    
    from_name = Column(String)
    from_email = Column(String, nullable=False)
    to_addresses = Column(Text)  # JSON array
    cc_addresses = Column(Text)  # JSON array
    bcc_addresses = Column(Text)  # JSON array
    
    subject = Column(String)
    date = Column(DateTime)
    snippet = Column(Text)
    folder = Column(String, default='INBOX')  # 'INBOX', 'Enviados', 'Deleted', etc.
    
    body_text = Column(Text)
    body_html = Column(Text)
    has_attachments = Column(Boolean, default=False)
    
    is_read = Column(Boolean, default=False)
    is_starred = Column(Boolean, default=False)
    
    created_at = Column(DateTime, server_default=func.now())


class Attachment(Base):
    """Email attachment."""
    __tablename__ = "attachments"
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    message_id = Column(String, ForeignKey("messages.id", ondelete="CASCADE"), nullable=False)
    filename = Column(String, nullable=False)
    mime_type = Column(String)
    size_bytes = Column(Integer)
    local_path = Column(String, nullable=False)


class Classification(Base):
    """AI classification result."""
    __tablename__ = "classifications"
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    message_id = Column(String, ForeignKey("messages.id", ondelete="CASCADE"), nullable=False, unique=True)
    
    # GPT classification
    gpt_label = Column(String)
    gpt_confidence = Column(Float)
    gpt_rationale = Column(Text)
    
    # Qwen classification
    qwen_label = Column(String)
    qwen_confidence = Column(Float)
    qwen_rationale = Column(Text)
    
    # Final decision
    final_label = Column(String, nullable=False)
    final_reason = Column(Text)
    decided_by = Column(String, nullable=False)  # 'consensus' | 'gpt_review' | 'rule_whitelist' | 'rule_multiple_recipients'
    decided_at = Column(DateTime, server_default=func.now())


class ServiceWhitelist(Base):
    """Whitelist for service email domains."""
    __tablename__ = "service_whitelist"
    __table_args__ = (UniqueConstraint('user_id', 'domain_pattern', name='uix_whitelist_user_domain'),)
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, ForeignKey("users.id"), nullable=False)
    domain_pattern = Column(String, nullable=False)
    description = Column(String)
    is_active = Column(Boolean, default=True)
    created_at = Column(DateTime, server_default=func.now())


class SenderRule(Base):
    """Rule to automatically move emails from a sender to a folder."""
    __tablename__ = "sender_rules"
    __table_args__ = (UniqueConstraint('user_id', 'sender_email', name='uix_sender_rule_user_email'),)
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, ForeignKey("users.id"), nullable=False)
    sender_email = Column(String, nullable=False)
    target_folder = Column(String, nullable=False)
    is_active = Column(Boolean, default=True)
    created_at = Column(DateTime, server_default=func.now())


class AuditLog(Base):
    """Audit log for operations."""
    __tablename__ = "audit_logs"
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    timestamp = Column(DateTime, server_default=func.now())
    message_id = Column(String)
    action = Column(String, nullable=False)
    payload = Column(Text)  # JSON
    status = Column(String)  # 'success' | 'error'
    error_message = Column(Text)


class Category(Base):
    """Email classification category."""
    __tablename__ = "categories"
    __table_args__ = (UniqueConstraint('user_id', 'key', name='uix_category_user_key'),)
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, ForeignKey("users.id"), nullable=False)
    key = Column(String, nullable=False)  # e.g., "Interesantes" (removed unique=True to allow per-user duplicates)
    name = Column(String, nullable=False)  # Display name
    description = Column(String)
    ai_instruction = Column(Text, nullable=False)  # Rule for AI
    icon = Column(String)  # Emoji
    is_system = Column(Boolean, default=False)  # If true, cannot be deleted
    created_at = Column(DateTime, server_default=func.now())


class AIConfig(Base):
    """AI Classification API configuration."""
    __tablename__ = "ai_config"
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    api_url = Column(String, nullable=False)
    api_key = Column(String, nullable=False)
    primary_model = Column(String, nullable=False)
    secondary_model = Column(String, nullable=False)
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())
