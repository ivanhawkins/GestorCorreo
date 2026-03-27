"""
Migration: Add 'folder' column to messages table.
Run once on production: python migrate_add_folder.py
"""
import sqlite3
import sys
from pathlib import Path

# Detect database path
db_candidates = [
    Path(__file__).parent / "mail_manager.db",
    Path(__file__).parent / "data" / "mail_manager.db",
    Path(__file__).parent.parent / "mail_manager.db",
]

db_path = None
for candidate in db_candidates:
    if candidate.exists():
        db_path = candidate
        break

if not db_path:
    print("❌ Could not find mail_manager.db. Specify path as first argument.")
    print("   Usage: python migrate_add_folder.py /path/to/mail_manager.db")
    if len(sys.argv) > 1:
        db_path = Path(sys.argv[1])
    else:
        sys.exit(1)

print(f"Using database: {db_path}")

conn = sqlite3.connect(str(db_path))
cursor = conn.cursor()

# Check if column already exists
cursor.execute("PRAGMA table_info(messages)")
columns = [row[1] for row in cursor.fetchall()]

if "folder" in columns:
    print("✅ Column 'folder' already exists. Nothing to do.")
else:
    print("Adding column 'folder' to messages table...")
    cursor.execute("ALTER TABLE messages ADD COLUMN folder TEXT DEFAULT 'INBOX'")
    
    # Mark sent messages (imap_uid=0) as Enviados
    cursor.execute(
        "UPDATE messages SET folder = 'Enviados' WHERE imap_uid = 0"
    )
    
    conn.commit()
    print(f"✅ Migration complete. 'folder' column added with default 'INBOX'.")
    print(f"   Sent messages (imap_uid=0) updated to folder='Enviados'.")

conn.close()
