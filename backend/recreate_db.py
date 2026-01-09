"""
Recreate database using raw SQL
"""
import sqlite3
import os

# Remove old database
db_path = 'mail_manager.db'
if os.path.exists(db_path):
    os.remove(db_path)
    print(f"Removed old database: {db_path}")

# Create new database
conn = sqlite3.connect(db_path)
cursor = conn.cursor()

# Create accounts table
cursor.execute('''
CREATE TABLE accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email_address VARCHAR NOT NULL UNIQUE,
    imap_host VARCHAR NOT NULL,
    imap_port INTEGER NOT NULL,
    smtp_host VARCHAR NOT NULL,
    smtp_port INTEGER NOT NULL,
    username VARCHAR NOT NULL,
    encrypted_password VARCHAR NOT NULL,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
''')

# Create messages table
cursor.execute('''
CREATE TABLE messages (
    id VARCHAR PRIMARY KEY,
    account_id INTEGER NOT NULL,
    imap_uid INTEGER NOT NULL,
    message_id VARCHAR NOT NULL,
    thread_id VARCHAR,
    from_name VARCHAR,
    from_email VARCHAR NOT NULL,
    to_addresses TEXT,
    cc_addresses TEXT,
    bcc_addresses TEXT,
    subject VARCHAR,
    date DATETIME,
    snippet TEXT,
    body_text TEXT,
    body_html TEXT,
    has_attachments BOOLEAN DEFAULT 0,
    is_read BOOLEAN DEFAULT 0,
    is_starred BOOLEAN DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
)
''')

# Create attachments table
cursor.execute('''
CREATE TABLE attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id VARCHAR NOT NULL,
    filename VARCHAR NOT NULL,
    mime_type VARCHAR,
    size_bytes INTEGER,
    local_path VARCHAR NOT NULL,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
)
''')

# Create classifications table
cursor.execute('''
CREATE TABLE classifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id VARCHAR NOT NULL UNIQUE,
    gpt_label VARCHAR,
    gpt_confidence REAL,
    gpt_rationale TEXT,
    qwen_label VARCHAR,
    qwen_confidence REAL,
    qwen_rationale TEXT,
    final_label VARCHAR NOT NULL,
    final_reason TEXT,
    decided_by VARCHAR NOT NULL,
    decided_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
)
''')

# Create service_whitelist table
cursor.execute('''
CREATE TABLE service_whitelist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_pattern VARCHAR NOT NULL UNIQUE,
    description VARCHAR,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
''')

# Create audit_logs table
cursor.execute('''
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    message_id VARCHAR,
    action VARCHAR NOT NULL,
    payload TEXT,
    status VARCHAR,
    error_message TEXT
)
''')

conn.commit()
conn.close()

print("✅ Database recreated successfully!")
print("Tables created:")
print("  - accounts")
print("  - messages")
print("  - attachments")
print("  - classifications")
print("  - service_whitelist")
print("  - audit_logs")
