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
import asyncio
import uuid
from functools import partial

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
    
    async def connect(self, max_retries: int = 3) -> bool:
        """Async wrapper for connect."""
        loop = asyncio.get_running_loop()
        return await loop.run_in_executor(None, partial(self._connect_sync, max_retries))

    def _connect_sync(self, max_retries: int = 3) -> bool:
        """
        Connect to IMAP server with retry logic (Blocking).
        
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
    
    async def disconnect(self):
        """Async wrapper for disconnect."""
        if self.connection:
            loop = asyncio.get_running_loop()
            await loop.run_in_executor(None, self._disconnect_sync)

    def _disconnect_sync(self):
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
    
    async def list_folders(self) -> List[str]:
        """Async wrapper for list_folders."""
        loop = asyncio.get_running_loop()
        return await loop.run_in_executor(None, self._list_folders_sync)

    def _list_folders_sync(self) -> List[str]:
        """List all IMAP folders (Blocking)."""
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
    
    async def select_folder(self, folder: str = "INBOX") -> bool:
        """Async wrapper for select_folder."""
        loop = asyncio.get_running_loop()
        return await loop.run_in_executor(None, partial(self._select_folder_sync, folder))

    def _select_folder_sync(self, folder: str = "INBOX") -> bool:
        """Select an IMAP folder (Blocking)."""
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
    
    async def get_new_message_uids(self, last_uid: int = 0) -> List[int]:
        """Async wrapper for get_new_message_uids."""
        loop = asyncio.get_running_loop()
        return await loop.run_in_executor(None, partial(self._get_new_message_uids_sync, last_uid))

    def _get_new_message_uids_sync(self, last_uid: int = 0) -> List[int]:
        """Get UIDs of new messages since last_uid (Blocking)."""
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
    
    async def fetch_message_headers(self, uid: int) -> Optional[Dict]:
        """Async wrapper for fetch_message_headers."""
        loop = asyncio.get_running_loop()
        return await loop.run_in_executor(None, partial(self._fetch_message_headers_sync, uid))

    def _fetch_message_headers_sync(self, uid: int) -> Optional[Dict]:
        """Fetch message headers and basic info (Blocking)."""
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
        
    async def fetch_full_message_body(self, uid: int) -> Optional[Dict]:
        """Async wrapper for fetch_full_message_body."""
        loop = asyncio.get_running_loop()
        return await loop.run_in_executor(None, partial(self._fetch_full_message_body_sync, uid))

    def _fetch_full_message_body_sync(self, uid: int) -> Optional[Dict]:
        """Fetch and parse complete message body with attachments (Blocking)."""
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
):
    """
    Synchronize messages from an account with progress yielding.
    
    Yields:
        Dict with sync status and progress
    """
    imap = IMAPService(account, password)
    
    
    try:
        # Handle POP3 protocol
        if account.protocol == 'pop3':
            logger.info(f"Using POP3 protocol for {account.email_address}")
            from app.services.pop3_service import POP3Service
            
            pop3 = POP3Service(account, password)
            
            yield {'status': 'connecting', 'message': f'Connecting to POP3 server {account.imap_host}...'}
            
            if not await pop3.connect():
                yield {'status': 'error', 'error': 'Failed to connect to POP3 server'}
                return
            
            yield {'status': 'connected', 'message': 'Connected to POP3 server'}
            
            # Get message count
            message_count = await pop3.get_message_count()
            logger.info(f"Found {message_count} messages in POP3 mailbox")
            
            # Retrieve UIDL map (msg_num -> uid)
            uid_map = await pop3.get_all_uidls()
            logger.info(f"Retrieved {len(uid_map)} UIDLs from server")
            
            # Load cached UIDs
            from pathlib import Path
            import json
            
            cache_dir = Path(__file__).parent.parent.parent.parent / "data" / "cache"
            cache_dir.mkdir(parents=True, exist_ok=True)
            cache_file = cache_dir / f"pop3_uids_{account.id}.json"
            
            cached_uids = set()
            if cache_file.exists():
                try:
                    with open(cache_file, 'r') as f:
                        cached_uids = set(json.load(f))
                    logger.info(f"Loaded {len(cached_uids)} cached UIDs")
                except Exception as e:
                    logger.error(f"Failed to load UID cache: {e}")
            
            # Determine new messages
            new_msg_nums = []
            for msg_num, uid in uid_map.items():
                if uid not in cached_uids:
                    new_msg_nums.append((msg_num, uid))
            
            # Sort by msg_num
            new_msg_nums.sort(key=lambda x: x[0])
            
            total_new = len(new_msg_nums)
            logger.info(f"Identified {total_new} truly new messages")
            
            yield {
                'status': 'found_messages',
                'total': total_new,
                'message': f'Found {total_new} new messages to download'
            }
            
            # Fetch existing message IDs from database to avoid duplicates (double check)
            existing_result = await db.execute(
                select(Message.message_id).where(Message.account_id == account.id)
            )
            existing_ids = {row[0] for row in existing_result.all()}
            
            saved_count = 0
            new_message_ids = []
            
            # Fetch and save NEW messages
            for index, (msg_num, uid) in enumerate(new_msg_nums):
                yield {
                    'status': 'downloading',
                    'current': index + 1,
                    'total': total_new,
                    'message': f'Downloading new message {index + 1} of {total_new}...'
                }
                
                # Fetch only headers first to check Message-ID duplication
                # (Safety net in case UIDL is new but message ID exists e.g. from previous manual syncs)
                headers = await pop3.fetch_headers_only(msg_num)
                
                if headers and headers['message_id'] in existing_ids:
                    # It exists in DB but not in cache? Add to cache and skip.
                    cached_uids.add(uid)
                    continue

                if not headers:
                     # Fallback to full fetch
                     msg = await pop3.fetch_message(msg_num)
                     if not msg: continue
                     headers = await pop3.get_message_headers(msg)
                
                body_data = await pop3.fetch_message(msg_num) # Re-fetch full for body (optimization: fetch_message_body logic inside fetch_message?)
                # Wait, pop3.fetch_message returns 'msg' object, then we call get_message_body(msg)
                # Correction:
                msg = await pop3.fetch_message(msg_num)
                if not msg: continue
                body_data = await pop3.get_message_body(msg)
                
                # ... same saving logic ...
                # Create message record
                from_name, from_email = '', headers['from']
                if '<' in headers['from'] and '>' in headers['from']:
                    from_name = headers['from'].split('<')[0].strip().strip('"')
                    from_email = headers['from'].split('<')[1].split('>')[0].strip()
                
                message = Message(
                    id=str(uuid.uuid4()),
                    account_id=account.id,
                    imap_uid=msg_num,  # We can store msg_num, but it's volatile.
                    message_id=headers['message_id'],
                    subject=headers['subject'],
                    from_name=from_name,
                    from_email=from_email,
                    to_addresses=json.dumps([headers['to']]) if headers['to'] else json.dumps([]),
                    cc_addresses=json.dumps([headers['cc']]) if headers.get('cc') else json.dumps([]),
                    bcc_addresses=None,
                    date=headers['date'],
                    body_text=body_data.get('body_text'),
                    body_html=body_data.get('body_html'),
                    is_read=False,
                    is_starred=False,
                    has_attachments=len(body_data.get('attachments', [])) > 0
                )
                
                db.add(message)
                saved_count += 1
                new_message_ids.append(message.id)
                
                # Add to cache
                cached_uids.add(uid)
                
                # Save attachments
                if body_data.get('attachments'):
                    from app.models import Attachment
                    from pathlib import Path
                    
                    attachments_dir = Path(__file__).parent.parent.parent.parent / "data" / "attachments"
                    attachments_dir.mkdir(parents=True, exist_ok=True)
                    
                    for att_data in body_data['attachments']:
                        local_path = ''
                        if att_data.get('content'):
                            try:
                                safe_filename = att_data.get('filename', 'unknown').replace('/', '_').replace('\\', '_')
                                unique_filename = f"{uuid.uuid4().hex}_{safe_filename}"
                                file_path = attachments_dir / unique_filename
                                with open(file_path, 'wb') as f:
                                    f.write(att_data['content'])
                                local_path = str(file_path.relative_to(attachments_dir.parent))
                            except Exception as e:
                                logger.error(f"Failed to save attachment: {e}")

                        attachment = Attachment(
                            message_id=message.id,
                            filename=att_data.get('filename', 'unknown'),
                            mime_type=att_data.get('mime_type'),
                            size_bytes=att_data.get('size_bytes', 0),
                            local_path=local_path
                        )
                        db.add(attachment)
                
                # Commit period
                if saved_count % 5 == 0:
                    await db.commit()
                    # Also save cache periodically
                    try:
                        with open(cache_file, 'w') as f:
                            json.dump(list(cached_uids), f)
                    except Exception as e:
                        logger.error(f"Failed to save cache: {e}")
            
            # Final commit and cache save
            await db.commit()
            try:
                with open(cache_file, 'w') as f:
                    json.dump(list(cached_uids), f)
            except Exception as e:
                logger.error(f"Failed to save final cache: {e}")

            await pop3.disconnect()
            
            logger.info(f"POP3 sync completed: {saved_count} new messages saved")
            
            yield {
                'status': 'success',
                'new_messages': saved_count,
                'new_message_ids': new_message_ids
            }
            return

        # Continue with IMAP for imap protocol
        logger.info(f"Starting sync for account {account.email_address}")
        yield {'status': 'connecting', 'message': f'Connecting to {account.imap_host}...'}
        
        try:
            if not await imap.connect():
                error_msg = 'Failed to connect to IMAP server'
                logger.error(f"Sync failed for {account.email_address}: {error_msg}")
                
                # Update account with error
                account.last_sync_error = error_msg
                await db.commit()
                
                yield {
                    'status': 'error',
                    'error': error_msg
                }
                return
        except (IMAPConnectionError, IMAPAuthenticationError) as e:
            error_msg = str(e)
            logger.error(f"Sync failed for {account.email_address}: {error_msg}")
            
            # Update account with error
            account.last_sync_error = error_msg
            await db.commit()
            
            yield {
                'status': 'error',
                'error': error_msg
            }
            return
        
        # Select folder
        yield {'status': 'selecting_folder', 'message': f'Selecting {folder}...'}
        if not await imap.select_folder(folder):
            error_msg = f'Failed to select folder {folder}'
            logger.error(error_msg)
            
            account.last_sync_error = error_msg
            await db.commit()
            
            yield {
                'status': 'error',
                'error': error_msg
            }
            return
        
        # Get last synced UID for this account
        yield {'status': 'checking_new', 'message': 'Checking for new messages...'}
        result = await db.execute(
            select(Message)
            .where(Message.account_id == account.id)
            .order_by(Message.imap_uid.desc())
            .limit(1)
        )
        last_message = result.scalar_one_or_none()
        last_uid = last_message.imap_uid if last_message else 0
        
        with open("d:\\proyectos\\programasivan\\Mail\\debug_sync.txt", "a") as f:
            f.write(f"DEBUG: Account {account.id} ({account.email_address}) - Last UID in DB: {last_uid}\n")
            if last_message:
                 f.write(f"DEBUG: Found message ID {last_message.id} with UID {last_message.imap_uid}\n")
            else:
                 f.write("DEBUG: No last message found\n")
        
        logger.info(f"Last synced UID for {account.email_address}: {last_uid}")
        
        # Get new message UIDs
        new_uids = await imap.get_new_message_uids(last_uid)
        
        if not new_uids:
            logger.info(f"No new messages for {account.email_address}")
            
            # Clear last sync error on successful sync
            account.last_sync_error = None
            await db.commit()
            
            yield {
                'status': 'success',
                'new_messages': 0,
                'total_messages': 0
            }
            return
        
        total_messages = len(new_uids)
        logger.info(f"Found {total_messages} new messages for {account.email_address}")
        yield {
            'status': 'found_messages', 
            'total': total_messages, 
            'message': f'Found {total_messages} new messages'
        }
        
        # Fetch and save new messages
        saved_count = 0
        new_message_ids = []
        for index, uid in enumerate(new_uids):
            # Yield progress every message (or every N messages if too fast, but 1 by 1 is good for feedback)
            yield {
                'status': 'downloading',
                'current': index + 1,
                'total': total_messages,
                'message': f'Downloading message {index + 1} of {total_messages}...'
            }

            try:
                headers = await imap.fetch_message_headers(uid)
                if not headers:
                    logger.warning(f"Skipping UID {uid} - failed to fetch headers")
                    continue
                
                # Fetch full message body
                body_data = await imap.fetch_full_message_body(uid)
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
                new_message_ids.append(message.id)
                
                # Save attachments to database if any
                if body_data and body_data.get('attachments'):
                    from app.models import Attachment
                    for att_data in body_data['attachments']:
                        attachment = Attachment(
                            message_id=message.id,
                            filename=att_data.get('filename', 'unknown'),
                            mime_type=att_data.get('mime_type'),
                            size_bytes=att_data.get('size_bytes', 0),
                            local_path=att_data.get('local_path', '')
                        )
                        db.add(attachment)

                # Update mailbox storage usage
                msg_size = (len(body_text) if body_text else 0) + (len(body_html) if body_html else 0)
                if account.mailbox_storage_bytes is None:
                    account.mailbox_storage_bytes = 0
                account.mailbox_storage_bytes += msg_size
                            
                # Commit after EACH message to ensure isolation of errors
                # This prevents one bad message from rolling back the entire batch
                await db.commit()
                
            except Exception as e:
                await db.rollback()
                logger.error(f"Error processing message UID {uid}: {e}")
                # Continue to next message
                continue
        
        await db.commit()
        
        # Clear last sync error on successful sync
        account.last_sync_error = None
        await db.commit()
        
        logger.info(f"Sync completed for {account.email_address}: {saved_count} messages saved")
        
        yield {
            'status': 'success',
            'new_messages': saved_count,
            'total_messages': total_messages,
            'new_message_ids': new_message_ids
        }
    
    except Exception as e:
        await db.rollback()
        error_msg = f"{type(e).__name__}: {str(e)}"
        logger.error(f"Unexpected error during sync for {account.email_address}: {error_msg}")
        
        # Update account with error
        account.last_sync_error = error_msg
        await db.commit()
        
        yield {
            'status': 'error',
            'error': error_msg
        }
    
    finally:
        await imap.disconnect()
