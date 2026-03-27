"""
Security utilities for credential encryption.
"""
from cryptography.fernet import Fernet
import keyring
import base64
import hashlib
import uuid
import platform


def get_machine_id() -> str:
    """Get a unique machine identifier."""
    try:
        # Try to get machine UUID
        if platform.system() == 'Windows':
            import subprocess
            result = subprocess.check_output('wmic csproduct get uuid', shell=True)
            return result.decode().split('\n')[1].strip()
        else:
            # For Linux/Mac, use /etc/machine-id or fallback
            try:
                with open('/etc/machine-id', 'r') as f:
                    return f.read().strip()
            except:
                return str(uuid.getnode())
    except:
        # Fallback to MAC address
        return str(uuid.getnode())


def get_encryption_key() -> bytes:
    """Get or create encryption key for credentials."""
    service_name = "mail_manager"
    key_name = "encryption_key"
    
    try:
        # Try to get existing key from keyring
        stored_key = keyring.get_password(service_name, key_name)
        if stored_key:
            return stored_key.encode()
    except:
        pass
    
    # Generate new key based on machine ID
    machine_id = get_machine_id()
    key_material = hashlib.pbkdf2_hmac(
        'sha256',
        machine_id.encode(),
        b'mail_manager_salt_v1',
        100000,
        dklen=32
    )
    key = base64.urlsafe_b64encode(key_material)
    
    # Try to store in keyring
    try:
        keyring.set_password(service_name, key_name, key.decode())
    except:
        # If keyring fails, we'll use the derived key anyway
        pass
    
    return key


def encrypt_password(password: str) -> str:
    """Encrypt a password."""
    key = get_encryption_key()
    cipher = Fernet(key)
    encrypted = cipher.encrypt(password.encode())
    return encrypted.decode()


def decrypt_password(encrypted_password: str) -> str:
    """Decrypt a password."""
    key = get_encryption_key()
    cipher = Fernet(key)
    decrypted = cipher.decrypt(encrypted_password.encode())
    return decrypted.decode()
