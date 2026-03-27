"""
MIME parsing service for email body and attachments.
"""
import email
import logging
from email.message import Message as EmailMessage
from email.header import decode_header
from typing import List, Dict, Optional, Tuple
import os
from pathlib import Path
import uuid
import base64
import quopri

logger = logging.getLogger(__name__)


class MIMEParser:
    """Parser for MIME email messages."""
    
    def __init__(self, attachments_dir: str = None):
        """Initialize MIME parser."""
        if attachments_dir is None:
            # Default to data/attachments
            self.attachments_dir = Path(__file__).parent.parent.parent.parent / "data" / "attachments"
        else:
            self.attachments_dir = Path(attachments_dir)
        
        self.attachments_dir.mkdir(parents=True, exist_ok=True)
    
    def parse_message(self, raw_email: bytes) -> Dict:
        """
        Parse a complete email message.
        
        Returns:
            Dict with body_text, body_html, and attachments list
        """
        msg = email.message_from_bytes(raw_email)
        
        body_text = ""
        body_html = ""
        attachments = []
        
        if msg.is_multipart():
            # Process multipart message
            for part in msg.walk():
                content_type = part.get_content_type()
                content_disposition = str(part.get("Content-Disposition", ""))
                
                # Skip multipart containers
                if part.is_multipart():
                    continue
                
                # Determine if this part is an attachment:
                # 1. Explicit Content-Disposition: attachment
                is_explicit_attachment = "attachment" in content_disposition
                # 2. Inline with a filename (e.g. Content-Disposition: inline; filename="x.pdf")
                is_inline_with_name = "inline" in content_disposition and part.get_filename()
                # 3. Has filename in Content-Type but no Content-Disposition (some older mail clients)
                has_filename_no_disposition = (
                    not content_disposition and
                    part.get_filename() and
                    content_type not in ("text/plain", "text/html")
                )
                
                if is_explicit_attachment or is_inline_with_name or has_filename_no_disposition:
                    is_inline = not is_explicit_attachment
                    attachment_info = self._process_attachment(part, is_inline=is_inline)
                    if attachment_info:
                        attachments.append(attachment_info)
                
                # Extract body parts (only when not an attachment)
                elif content_type == "text/plain" and not body_text:
                    body_text = self._decode_payload(part)
                
                elif content_type == "text/html" and not body_html:
                    body_html = self._decode_payload(part)
        
        else:
            # Single part message
            content_type = msg.get_content_type()
            
            if content_type == "text/plain":
                body_text = self._decode_payload(msg)
            elif content_type == "text/html":
                body_html = self._decode_payload(msg)
        
        return {
            "body_text": body_text,
            "body_html": body_html,
            "attachments": attachments,
            "has_attachments": len(attachments) > 0
        }
    
    def _decode_payload(self, part: EmailMessage) -> str:
        """Decode email part payload."""
        try:
            payload = part.get_payload(decode=True)
            if payload is None:
                return ""
            
            # Try to decode with charset
            charset = part.get_content_charset() or 'utf-8'
            try:
                return payload.decode(charset, errors='ignore')
            except:
                return payload.decode('utf-8', errors='ignore')
        except Exception as e:
            logger.error(f"Error decoding payload: {e}")
            return ""
    
    def _process_attachment(self, part: EmailMessage, is_inline: bool = False) -> Optional[Dict]:
        """Process and save an attachment."""
        try:
            # Get filename
            filename = part.get_filename()
            if not filename:
                # Generate filename if not provided
                ext = self._guess_extension(part.get_content_type())
                filename = f"attachment_{uuid.uuid4().hex[:8]}{ext}"
            else:
                # Decode filename if encoded
                filename = self._decode_filename(filename)
            
            # Get payload
            payload = part.get_payload(decode=True)
            if not payload:
                return None
            
            # Generate unique filename to avoid collisions
            unique_filename = f"{uuid.uuid4().hex}_{filename}"
            file_path = self.attachments_dir / unique_filename
            
            # Save to disk
            with open(file_path, 'wb') as f:
                f.write(payload)
            
            # Get content type and size
            content_type = part.get_content_type()
            size_bytes = len(payload)
            
            return {
                "filename": filename,
                "local_path": str(file_path.relative_to(file_path.parent.parent)),  # Relative to data/
                "mime_type": content_type,
                "size_bytes": size_bytes,
                "is_inline": is_inline
            }
        
        except Exception as e:
            logger.error(f"Error processing attachment '{filename}': {e}")
            return None
    
    def _decode_filename(self, filename: str) -> str:
        """Decode encoded filename."""
        try:
            decoded_parts = []
            for part, encoding in decode_header(filename):
                if isinstance(part, bytes):
                    decoded_parts.append(part.decode(encoding or 'utf-8', errors='ignore'))
                else:
                    decoded_parts.append(str(part))
            return ''.join(decoded_parts)
        except:
            return filename
    
    def _guess_extension(self, content_type: str) -> str:
        """Guess file extension from content type."""
        extensions = {
            'image/jpeg': '.jpg',
            'image/png': '.png',
            'image/gif': '.gif',
            'application/pdf': '.pdf',
            'application/zip': '.zip',
            'text/plain': '.txt',
            'text/html': '.html',
        }
        return extensions.get(content_type, '.bin')


def fetch_full_message(imap_connection, uid: int) -> Optional[bytes]:
    """
    Fetch complete message body from IMAP.
    
    Args:
        imap_connection: Active IMAP connection
        uid: Message UID
    
    Returns:
        Raw email bytes or None
    """
    try:
        status, data = imap_connection.uid('fetch', str(uid), '(RFC822)')
        
        if status != 'OK' or not data or not data[0]:
            return None
        
        # data[0] is a tuple: (response_line, raw_email)
        if isinstance(data[0], tuple):
            return data[0][1]
        
        return None
    
    except Exception as e:
        logger.error(f"Error fetching full message UID {uid}: {e}")
        return None
