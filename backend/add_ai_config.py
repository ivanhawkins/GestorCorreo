"""
Migration script to add AI configuration table.
Create ai_config table for storing remote AI API settings.
"""
import sqlite3
from pathlib import Path
from datetime import datetime

# Use the same path as the app
DATA_DIR = Path(__file__).parent.parent / "data"
DATA_DIR.mkdir(exist_ok=True)
DB_PATH = DATA_DIR / "mail.db"

def migrate():
    print(f"Using database: {DB_PATH}")
    print(f"Database exists: {DB_PATH.exists()}")

    conn = sqlite3.connect(str(DB_PATH))
    cursor = conn.cursor()

    # Create ai_config table
    cursor.execute('''
    CREATE TABLE IF NOT EXISTS ai_config (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        api_url VARCHAR NOT NULL,
        api_key VARCHAR NOT NULL,
        primary_model VARCHAR NOT NULL,
        secondary_model VARCHAR NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
    ''')

    # Check if data already exists
    cursor.execute("SELECT COUNT(*) FROM ai_config")
    count = cursor.fetchone()[0]

    if count == 0:
        # Insert default values
        cursor.execute('''
        INSERT INTO ai_config (api_url, api_key, primary_model, secondary_model, updated_at)
        VALUES (?, ?, ?, ?, ?)
        ''', (
            'https://192.168.1.45/chat/models',
            'OllamaAPI_2024_K8mN9pQ2rS5tU7vW3xY6zA1bC4eF8hJ0lM',
            'gpt-oss:120b-cloud',
            'qwen3-coder:480b-cloud',
            datetime.utcnow()
        ))
        print("✓ Inserted default AI configuration")
    else:
        print(f"✓ AI config already exists ({count} records)")

    conn.commit()

    # Verify
    cursor.execute("SELECT * FROM ai_config")
    result = cursor.fetchone()
    print(f"\nCurrent config:")
    print(f"  ID: {result[0]}")
    print(f"  API URL: {result[1]}")
    print(f"  Primary Model: {result[3]}")
    print(f"  Secondary Model: {result[4]}")

    conn.close()
    print("\n✓ Migration complete!")

if __name__ == "__main__":
    migrate()
