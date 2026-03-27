
import asyncio
import sys
from pathlib import Path
from sqlalchemy import text
from app.database import engine

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent))

async def migrate_account_features():
    print("Migrating Account features (Soft Delete, Storage, Protocol)...")
    
    async with engine.begin() as conn:
        # 1. Add is_deleted
        try:
            await conn.execute(text("ALTER TABLE accounts ADD COLUMN is_deleted BOOLEAN DEFAULT 0"))
            print("Added is_deleted column")
        except Exception as e:
            print(f"is_deleted might already exist: {e}")

        # 2. Add protocol
        try:
            await conn.execute(text("ALTER TABLE accounts ADD COLUMN protocol VARCHAR DEFAULT 'imap'"))
            print("Added protocol column")
        except Exception as e:
            print(f"protocol might already exist: {e}")
            
        # 3. Add mailbox_storage_bytes
        try:
            await conn.execute(text("ALTER TABLE accounts ADD COLUMN mailbox_storage_bytes INTEGER"))
            print("Added mailbox_storage_bytes column")
        except Exception as e:
            print(f"mailbox_storage_bytes might already exist: {e}")

        # 4. Add mailbox_storage_limit
        try:
            await conn.execute(text("ALTER TABLE accounts ADD COLUMN mailbox_storage_limit INTEGER"))
            print("Added mailbox_storage_limit column")
        except Exception as e:
            print(f"mailbox_storage_limit might already exist: {e}")

    print("Migration completed.")

if __name__ == "__main__":
    asyncio.run(migrate_account_features())
