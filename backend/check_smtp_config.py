"""
Check SMTP configuration in database
"""
import sqlite3
from pathlib import Path

DB_PATH = Path(__file__).parent.parent / "data" / "mail.db"

conn = sqlite3.connect(str(DB_PATH))
cursor = conn.cursor()

# Get all accounts with SMTP info
cursor.execute("""
    SELECT id, email_address, smtp_host, smtp_port, username, is_active 
    FROM accounts 
    WHERE is_active = 1
""")

results = cursor.fetchall()
print("Active accounts SMTP configuration:")
print("-" * 80)
for row in results:
    print(f"Account ID: {row[0]}")
    print(f"  Email: {row[1]}")
    print(f"  SMTP Host: {row[2]}")
    print(f"  SMTP Port: {row[3]}")
    print(f"  Username: {row[4]}")
    print(f"  Active: {row[5]}")
    print()

conn.close()
