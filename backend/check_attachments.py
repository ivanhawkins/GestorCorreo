"""
Check attachments in database
"""
import sqlite3
from pathlib import Path

DB_PATH = Path(__file__).parent.parent / "data" / "mail.db"

conn = sqlite3.connect(str(DB_PATH))
cursor = conn.cursor()

# Check attachments table
cursor.execute("SELECT COUNT(*) FROM attachments")
count = cursor.fetchone()[0]
print(f"Total attachments in DB: {count}")

# Check messages with attachments flag
cursor.execute("SELECT COUNT(*) FROM messages WHERE has_attachments = 1")
flagged = cursor.fetchone()[0]
print(f"Messages flagged has_attachments=True: {flagged}")

# Sample a few
cursor.execute("SELECT id, message_id, filename, size_bytes FROM attachments LIMIT 5")
results = cursor.fetchall()
if results:
    print(f"\nSample attachments:")
    for row in results:
        print(f"  ID: {row[0]}, Message: {row[1][:20]}..., Filename: {row[2]}, Size: {row[3]} bytes")
else:
    print("\nNo attachments found")

conn.close()
