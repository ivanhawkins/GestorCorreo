"""
IMAP service for email synchronization with improved error handling and logging.
"""
import imaplib
import email
import ssl
import socket
import time
from email.header import decode_header
from email.utils import parsedate_to_datetime
from typing import List, Dict, Optional, Tuple
import json
from datetime import datetime
import uuid

from app.models import Account, Message
from app.utils.logging_config import get_logger
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select

logger = get_logger(__name__)


class IMAPConnectionError(Exception):
    """Custom exception for IMAP connection errors."""
    pass


class IMAPAuthenticationError(Exception):
    """Custom exception for IMAP authentication errors."""
    pass


class IMAPService:
    """Service for IMAP operations with improved error handling."""
    
    def __init__(self, account: Account, password: str):
        """Initialize IMAP service with account credentials."""
        self.account = account
        self.password = password
        self.connection: Optional[imaplib.IMAP4_SSL] = None
        self.logger = logger
    
    def _create_ssl_context(self, verify: bool = True) -> ssl.SSLContext:
        """
        Create SSL context for IMAP connection.
        
        Args:
            verify: Whether to verify SSL certificates
            
        Returns:
            SSL context
        """
        context = ssl.create_default_context()
        
        if not verify:
            self.logger.warning(
                f"SSL verification disabled for {self.account.email_address}. "
                "This reduces security but may be necessary for self-signed certificates."
            )
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE
        
        return context
    
    def connect(self, max_retries: int = 3) -> bool:
        """
        Connect to IMAP server with retry logic and detailed error handling.
        
        Args:
            max_retries: Maximum number of connection attempts
            
        Returns:
            True if connection successful, False otherwise
        """
        for attempt in range(1, max_retries + 1):
            try:
                self.logger.info(
                    f"Attempting IMAP connection to {self.account.imap_host}:{self.account.imap_port} "
                    f"(attempt {attempt}/{max_retries}) for {self.account.email_address}"
                )
                
                # Step 1: Establish SSL connection
                try:
                    ssl_context = self._create_ssl_context(verify=self.account.ssl_verify)
                    self.connection = imaplib.IMAP4_SSL(
                        self.account.imap_host,
                        self.account.imap_port,
                        ssl_context=ssl_context,
                        timeout=self.account.connection_timeout
                    )
                    self.logger.info(f"SSL connection established to {self.account.imap_host}")
                    
                except ssl.SSLError as e:
                    self.logger.error(f"SSL error: {e}")
                    
                    # Try without SSL verification as fallback
                    if self.account.ssl_verify and attempt == max_retries:
                        self.logger.warning("Retrying with SSL verification disabled...")
                        ssl_context = self._create_ssl_context(verify=False)
                        self.connection = imaplib.IMAP4_SSL(
                            self.account.imap_host,
                            self.account.imap_port,
                            ssl_context=ssl_context,
                            timeout=self.account.connection_timeout
                        )
                        self.logger.info("SSL connection established (without verification)")
                    else:
                        raise
                
                # Step 2: Authenticate
                try:
                    self.logger.info(f"Authenticating as {self.account.username}...")
                    result = self.connection.login(self.account.username, self.password)
                    self.logger.info(f"Authentication successful: {result}")
                    
                    # Test connection by selecting INBOX
                    status, data = self.connection.select('INBOX')
                    if status == 'OK':
                        num_messages = int(data[0]) if data[0] else 0
                        self.logger.info(f"INBOX selected successfully. Messages: {num_messages}")
                    else:
                        self.logger.warning(f"INBOX selection returned status: {status}")
                    
                    return True
                    
                except imaplib.IMAP4.error as e:
                    error_msg = str(e).lower()
                    self.logger.error(f"Authentication failed: {e}")
                    
                    if 'authenticationfailed' in error_msg or 'invalid credentials' in error_msg:
                        raise IMAPAuthenticationError(
                            f"Authentication failed for {self.account.username}. "
                            "Please check your credentials. "
                            "For Gmail/Outlook, you may need an App Password."
                        )
                    else:
                        raise IMAPConnectionError(f"IMAP error during authentication: {e}")
            
            except socket.gaierror as e:
                self.logger.error(f"DNS resolution failed for {self.account.imap_host}: {e}")
                raise IMAPConnectionError(
                    f"Cannot resolve hostname '{self.account.imap_host}'. "
                    "Please check the IMAP host address."
                )
            
            except socket.timeout as e:
                self.logger.error(f"Connection timeout after {self.account.connection_timeout}s: {e}")
                if attempt < max_retries:
                    wait_time = 2 ** attempt  # Exponential backoff
                    self.logger.info(f"Retrying in {wait_time} seconds...")
                    time.sleep(wait_time)
                    continue
                else:
                    raise IMAPConnectionError(
                        f"Connection timeout to {self.account.imap_host}:{self.account.imap_port}. "
                        "The server may be down or unreachable."
                    )
            
            except ConnectionRefusedError as e:
                self.logger.error(f"Connection refused: {e}")
                raise IMAPConnectionError(
                    f"Connection refused by {self.account.imap_host}:{self.account.imap_port}. "
                    "Please check the host and port, and ensure IMAP is enabled."
                )
            
            except Exception as e:
                self.logger.error(f"Unexpected error during connection: {type(e).__name__}: {e}")
                if attempt < max_retries:
                    wait_time = 2 ** attempt
                    self.logger.info(f"Retrying in {wait_time} seconds...")
                    time.sleep(wait_time)
                    continue
                else:
                    raise IMAPConnectionError(f"Failed to connect after {max_retries} attempts: {e}")
        
        return False
    
    def disconnect(self):
        """Disconnect from IMAP server."""
        if self.connection:
            try:
                self.connection.logout()
                self.logger.info(f"Disconnected from {self.account.imap_host}")
            except Exception as e:
                self.logger.warning(f"Error during disconnect: {e}")
            finally:
                self.connection = None
    
    def _test_connection(self) -> bool:
        """
        Test if connection is still alive.
        
        Returns:
            True if connection is alive, False otherwise
        """
        if not self.connection:
            return False
        
        try:
            status, _ = self.connection.noop()
            return status == 'OK'
        except:
            return False
    
    def list_folders(self) -> List[str]:
        """List all IMAP folders."""
        if not self.connection:
            self.logger.error("Cannot list folders: not connected")
            return []
        
        try:
            self.logger.debug("Listing IMAP folders...")
            status, folders = self.connection.list()
            
            if status != 'OK':
                self.logger.error(f"Failed to list folders: {status}")
                return []
            
            folder_names = []
            for folder in folders:
                # Parse folder name from IMAP response
                parts = folder.decode().split('"')
                if len(parts) >= 3:
                    folder_names.append(parts[-2])
            
            self.logger.info(f"Found {len(folder_names)} folders")
            return folder_names
            
        except Exception as e:
            self.logger.error(f"Error listing folders: {type(e).__name__}: {e}")
            return []
    
    def select_folder(self, folder: str = "INBOX") -> bool:
        """Select an IMAP folder."""
        if not self.connection:
            self.logger.error("Cannot select folder: not connected")
            return False
        
        try:
            self.logger.debug(f"Selecting folder: {folder}")
            status, data = self.connection.select(folder)
            
            if status == 'OK':
                num_messages = int(data[0]) if data[0] else 0
                self.logger.info(f"Selected folder '{folder}' with {num_messages} messages")
                return True
            else:
                self.logger.error(f"Failed to select folder '{folder}': {status}")
                return False
                
        except Exception as e:
            self.logger.error(f"Error selecting folder '{folder}': {type(e).__name__}: {e}")
            return False
    
    def get_new_message_uids(self, last_uid: int = 0) -> List[int]:
        """Get UIDs of new messages since last_uid."""
        if not self.connection:
            self.logger.error("Cannot get message UIDs: not connected")
            return []
        
        try:
            # Search for messages with UID greater than last_uid
            search_criteria = f"UID {last_uid + 1}:*" if last_uid > 0 else "ALL"
            self.logger.debug(f"Searching for messages: {search_criteria}")
            
            status, data = self.connection.uid('search', None, search_criteria)
            
            if status != 'OK':
                self.logger.error(f"Search failed: {status}")
                return []
            
            uid_list = data[0].split()
            uids = [int(uid) for uid in uid_list]
            self.logger.info(f"Found {len(uids)} message UIDs")
            return uids
            
        except Exception as e:
            self.logger.error(f"Error getting message UIDs: {type(e).__name__}: {e}")
            return []
    
    def fetch_message_headers(self, uid: int) -> Optional[Dict]:
        """Fetch message headers and basic info."""
        if not self.connection:
            self.logger.error("Cannot fetch message: not connected")
            return None
        
        try:
            self.logger.debug(f"Fetching headers for message UID {uid}")
            
            # Fetch headers and basic body structure
            status, data = self.connection.uid(
                'fetch',
                str(uid),
                '(BODY.PEEK[HEADER] RFC822.SIZE FLAGS)'
            )
            
            if status != 'OK' or not data or not data[0]:
                self.logger.warning(f"Failed to fetch message UID {uid}: {status}")
                return None
            
            # Parse the response
            raw_email = data[0][1]
            msg = email.message_from_bytes(raw_email)
            
            # Extract headers
            subject = self._decode_header(msg.get('Subject', ''))
            from_header = self._decode_header(msg.get('From', ''))
            to_header = self._decode_header(msg.get('To', ''))
            cc_header = self._decode_header(msg.get('Cc', ''))
            date_header = msg.get('Date', '')
            message_id = msg.get('Message-ID', f'<generated-{uid}@local>')
            
            # Parse from address
            from_name, from_email = self._parse_email_address(from_header)
            
            # Parse date
            try:
                date = parsedate_to_datetime(date_header) if date_header else datetime.now()
            except:
                date = datetime.now()
                self.logger.warning(f"Could not parse date for UID {uid}: {date_header}")
            
            # Parse To/Cc addresses
            to_addresses = self._parse_address_list(to_header)
            cc_addresses = self._parse_address_list(cc_header)
            
            self.logger.debug(f"Successfully fetched headers for UID {uid}: {subject}")
            
            return {
                'uid': uid,
                'message_id': message_id,
                'subject': subject,
                'from_name': from_name,
                'from_email': from_email,
                'to_addresses': json.dumps(to_addresses),
                'cc_addresses': json.dumps(cc_addresses),
                'date': date,
                'snippet': f"{subject[:100]}..." if subject else "",
            }
            
        except Exception as e:
            self.logger.error(f"Error fetching message UID {uid}: {type(e).__name__}: {e}")
            return None
    
    def _decode_header(self, header: str) -> str:
        """Decode email header."""
        if not header:
            return ""
        
        decoded_parts = []
        for part, encoding in decode_header(header):
            if isinstance(part, bytes):
                try:
                    decoded_parts.append(part.decode(encoding or 'utf-8', errors='ignore'))
                except:
                    decoded_parts.append(part.decode('utf-8', errors='ignore'))
            else:
                decoded_parts.append(str(part))
        
        return ' '.join(decoded_parts)
    
    def _parse_email_address(self, address: str) -> Tuple[str, str]:
        """Parse email address into name and email."""
        if not address:
            return "", ""
        
        # Simple parsing (can be improved with email.utils.parseaddr)
        if '<' in address and '>' in address:
            name = address.split('<')[0].strip().strip('"')
            email_addr = address.split('<')[1].split('>')[0].strip()
            return name, email_addr
        else:
            return "", address.strip()
    
    def _parse_address_list(self, addresses: str) -> List[str]:
        """Parse comma-separated email addresses."""
        if not addresses:
            return []
        
        # Simple split by comma (can be improved)
        return [addr.strip() for addr in addresses.split(',') if addr.strip()]
    
    def fetch_full_message_body(self, uid: int) -> Optional[Dict]:
        """Fetch and parse complete message body with attachments."""
        if not self.connection:
            self.logger.error("Cannot fetch message body: not connected")
            return None
        
        try:
            from app.services.mime_parser import MIMEParser, fetch_full_message
            
            self.logger.debug(f"Fetching full message body for UID {uid}")
            
            # Fetch raw email
            raw_email = fetch_full_message(self.connection, uid)
            if not raw_email:
                self.logger.warning(f"Failed to fetch raw email for UID {uid}")
                return None
            
            # Parse with MIME parser
            parser = MIMEParser()
            parsed = parser.parse_message(raw_email)
            
            self.logger.debug(f"Successfully parsed message body for UID {uid}")
            return parsed
        
        except Exception as e:
            self.logger.error(f"Error fetching full message body for UID {uid}: {type(e).__name__}: {e}")
            return None


async def sync_account_messages(
    account: Account,
    password: str,
    db: AsyncSession,
    folder: str = "INBOX"
) -> Dict:
    """
    Synchronize messages from an account.
    
    Returns:
        Dict with sync statistics
    """
    imap = IMAPService(account, password)
    
    try:
        # Connect to IMAP
        logger.info(f"Starting sync for account {account.email_address}")
        
        try:
            if not imap.connect():
                error_msg = 'Failed to connect to IMAP server'
                logger.error(f"Sync failed for {account.email_address}: {error_msg}")
                
                # Update account with error
                account.last_sync_error = error_msg
                await db.commit()
                
                return {
                    'status': 'error',
                    'error': error_msg
                }
        except (IMAPConnectionError, IMAPAuthenticationError) as e:
            error_msg = str(e)
            logger.error(f"Sync failed for {account.email_address}: {error_msg}")
            
            # Update account with error
            account.last_sync_error = error_msg
            await db.commit()
            
            return {
                'status': 'error',
                'error': error_msg
            }
        
        # Select folder
        if not imap.select_folder(folder):
            error_msg = f'Failed to select folder {folder}'
            logger.error(error_msg)
            
            account.last_sync_error = error_msg
            await db.commit()
            
            return {
                'status': 'error',
                'error': error_msg
            }
        
        # Get last synced UID for this account
        result = await db.execute(
            select(Message)
            .where(Message.account_id == account.id)
            .order_by(Message.imap_uid.desc())
            .limit(1)
        )
        last_message = result.scalar_one_or_none()
        last_uid = last_message.imap_uid if last_message else 0
        
        logger.info(f"Last synced UID for {account.email_address}: {last_uid}")
        
        # Get new message UIDs
        new_uids = imap.get_new_message_uids(last_uid)
        
        if not new_uids:
            logger.info(f"No new messages for {account.email_address}")
            
            # Clear last sync error on successful sync
            account.last_sync_error = None
            await db.commit()
            
            return {
                'status': 'success',
                'new_messages': 0,
                'total_messages': 0
            }
        
        logger.info(f"Found {len(new_uids)} new messages for {account.email_address}")
        
        # Fetch and save new messages
        saved_count = 0
        for uid in new_uids:
            headers = imap.fetch_message_headers(uid)
            if not headers:
                logger.warning(f"Skipping UID {uid} - failed to fetch headers")
                continue
            
            # Fetch full message body
            body_data = imap.fetch_full_message_body(uid)
            body_text = body_data.get('body_text') if body_data else None
            body_html = body_data.get('body_html') if body_data else None
            has_attachments = len(body_data.get('attachments', [])) > 0 if body_data else False
            
            # Create message record
            message = Message(
                id=str(uuid.uuid4()),
                account_id=account.id,
                imap_uid=uid,
                message_id=headers['message_id'],
                subject=headers['subject'],
                from_name=headers['from_name'],
                from_email=headers['from_email'],
                to_addresses=headers['to_addresses'],
                cc_addresses=headers['cc_addresses'],
                date=headers['date'],
                snippet=headers['snippet'],
                body_text=body_text,
                body_html=body_html,
                is_read=False,
                is_starred=False,
                has_attachments=has_attachments
            )
            
            db.add(message)
            saved_count += 1
        
        await db.commit()
        
        # Clear last sync error on successful sync
        account.last_sync_error = None
        await db.commit()
        
        logger.info(f"Sync completed for {account.email_address}: {saved_count} messages saved")
        
        return {
            'status': 'success',
            'new_messages': saved_count,
            'total_messages': len(new_uids)
        }
    
    except Exception as e:
        await db.rollback()
        error_msg = f"{type(e).__name__}: {str(e)}"
        logger.error(f"Unexpected error during sync for {account.email_address}: {error_msg}")
        
        # Update account with error
        account.last_sync_error = error_msg
        await db.commit()
        
        return {
            'status': 'error',
            'error': error_msg
        }
    
    finally:
        imap.disconnect()
