"""
POP3 service for email synchronization.
"""
import poplib
import email
import ssl
from email.header import decode_header
from email.utils import parsedate_to_datetime
from typing import List, Dict, Optional
import asyncio
from functools import partial

from app.models import Account
from app.utils.logging_config import get_logger

logger = get_logger(__name__)


class POP3Service:
    """Service for POP3 operations."""
    
    def __init__(self, account: Account, password: str):
        """Initialize POP3 service with account credentials."""
        self.account = account
        self.password = password
        self.connection: Optional[poplib.POP3_SSL] = None
        self.logger = logger
    
    async def connect(self) -> bool:
        """Connect to POP3 server."""
        try:
            logger.info(f"Connecting to POP3 server {self.account.imap_host}:{self.account.imap_port}")
            
            loop = asyncio.get_event_loop()
            
            # Use SSL connection
            if self.account.imap_port == 995:
                # Create SSL context
                context = ssl.create_default_context()
                if not self.account.ssl_verify:
                    context.check_hostname = False
                    context.verify_mode = ssl.CERT_NONE
                
                # Connect with SSL
                self.connection = await loop.run_in_executor(
                    None,
                    partial(
                        poplib.POP3_SSL,
                        self.account.imap_host,
                        self.account.imap_port,
                        context=context,
                        timeout=self.account.connection_timeout
                    )
                )
            else:
                # Plain connection (port 110)
                self.connection = await loop.run_in_executor(
                    None,
                    partial(
                        poplib.POP3,
                        self.account.imap_host,
                        self.account.imap_port,
                        timeout=self.account.connection_timeout
                    )
                )
            
            # Authenticate
            await loop.run_in_executor(None, self.connection.user, self.account.username)
            await loop.run_in_executor(None, self.connection.pass_, self.password)
            
            logger.info(f"Successfully connected to POP3 server")
            return True
            
        except Exception as e:
            logger.error(f"Failed to connect to POP3: {type(e).__name__}: {str(e)}")
            return False
    
    async def get_message_count(self) -> int:
        """Get number of messages in mailbox."""
        try:
            loop = asyncio.get_event_loop()
            stat = await loop.run_in_executor(None, self.connection.stat)
            return stat[0]  # Returns (message_count, mailbox_size)
        except Exception as e:
            logger.error(f"Failed to get message count: {e}")
            return 0
    
    async def fetch_message(self, msg_num: int) -> Optional[email.message.Message]:
        """Fetch a single message by number."""
        try:
            loop = asyncio.get_event_loop()
            
            # Retrieve message
            response, lines, octets = await loop.run_in_executor(
                None,
                self.connection.retr,
                msg_num
            )
            
            # Join lines and parse
            msg_data = b'\r\n'.join(lines)
            msg = email.message_from_bytes(msg_data)
            
            return msg
            
        except Exception as e:
            logger.error(f"Failed to fetch message {msg_num}: {e}")
            return None
    
    async def get_message_headers(self, msg: email.message.Message) -> Dict:
        """Extract headers from message."""
        def decode_mime_header(header_value):
            if header_value is None:
                return ""
            decoded_parts = decode_header(header_value)
            header_text = ""
            for part, encoding in decoded_parts:
                if isinstance(part, bytes):
                    header_text += part.decode(encoding if encoding else 'utf-8', errors='ignore')
                else:
                    header_text += str(part)
            return header_text
        
        # Extract basic headers
        subject = decode_mime_header(msg.get('Subject', ''))
        from_addr = decode_mime_header(msg.get('From', ''))
        to_addr = decode_mime_header(msg.get('To', ''))
        cc_addr = decode_mime_header(msg.get('Cc', ''))
        
        # Parse date
        date_str = msg.get('Date')
        try:
            date_obj = parsedate_to_datetime(date_str) if date_str else None
        except:
            date_obj = None
        
        return {
            'message_id': msg.get('Message-ID', ''),
            'subject': subject,
            'from': from_addr,
            'to': to_addr,
            'cc': cc_addr,
            'date': date_obj,
            'in_reply_to': msg.get('In-Reply-To', ''),
            'references': msg.get('References', '')
        }

    async def fetch_headers_only(self, msg_num: int) -> Optional[Dict]:
        """Fetch only headers for a message using TOP command."""
        try:
            loop = asyncio.get_event_loop()
            # Fetch headers (0 lines of body)
            response, lines, octets = await loop.run_in_executor(
                None,
                self.connection.top,
                msg_num,
                0
            )
            
            # Parse headers
            msg_data = b'\r\n'.join(lines)
            msg = email.message_from_bytes(msg_data)
            
            # Reuse get_message_headers
            return await self.get_message_headers(msg)
            
        except Exception as e:
            logger.error(f"Failed to fetch headers only for {msg_num}: {e}")
            return None
    
    async def get_message_body(self, msg: email.message.Message) -> Dict:
        """Extract body and attachments from message."""
        body_text = ""
        body_html = ""
        attachments = []
        
        def process_part(part):
            nonlocal body_text, body_html, attachments
            
            content_type = part.get_content_type()
            content_disposition = str(part.get('Content-Disposition', ''))
            
            # Check if it's an attachment
            if 'attachment' in content_disposition:
                filename = part.get_filename()
                if filename:
                    filename = decode_header(filename)[0][0]
                    if isinstance(filename, bytes):
                        filename = filename.decode('utf-8', errors='ignore')
                    
                    attachments.append({
                        'filename': filename,
                        'mime_type': content_type,
                        'content': part.get_payload(decode=True),
                        'size_bytes': len(part.get_payload(decode=True) or b'')
                    })
            
            # Text content
            elif content_type == 'text/plain' and not body_text:
                try:
                    payload = part.get_payload(decode=True)
                    if payload:
                        charset = part.get_content_charset() or 'utf-8'
                        body_text = payload.decode(charset, errors='ignore')
                except:
                    pass
            
            # HTML content
            elif content_type == 'text/html' and not body_html:
                try:
                    payload = part.get_payload(decode=True)
                    if payload:
                        charset = part.get_content_charset() or 'utf-8'
                        body_html = payload.decode(charset, errors='ignore')
                except:
                    pass
        
        # Process message parts
        if msg.is_multipart():
            for part in msg.walk():
                process_part(part)
        else:
            process_part(msg)
        
        return {
            'body_text': body_text,
            'body_html': body_html,
            'attachments': attachments
        }
    
    async def get_all_uidls(self) -> Dict[int, str]:
        """Get list of (msg_num, uid) for all messages."""
        try:
            loop = asyncio.get_event_loop()
            response, lines, octets = await loop.run_in_executor(None, self.connection.uidl)
            
            uid_map = {}
            for line in lines:
                try:
                    # Format: b'1 start_uid_xxxxx'
                    parts = line.decode().split(' ')
                    if len(parts) >= 2:
                        msg_num = int(parts[0])
                        uid = parts[1]
                        uid_map[msg_num] = uid
                except Exception:
                    continue
            return uid_map
        except Exception as e:
            logger.error(f"Failed to get UIDLs: {e}")
            return {}

    async def disconnect(self):
        """Disconnect from POP3 server."""
        try:
            if self.connection:
                loop = asyncio.get_event_loop()
                await loop.run_in_executor(None, self.connection.quit)
                logger.info("Disconnected from POP3 server")
        except Exception as e:
            logger.error(f"Error disconnecting from POP3: {e}")

