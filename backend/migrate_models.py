
import asyncio
from sqlalchemy import text
from app.database import engine

async def migrate():
    print("Migrating database...")
    async with engine.begin() as conn:
        try:
            await conn.execute(text("ALTER TABLE accounts ADD COLUMN auto_sync_interval INTEGER DEFAULT 0"))
            print("Added auto_sync_interval")
        except Exception as e:
            print(f"auto_sync_interval might already exist: {e}")

        try:
             await conn.execute(text("ALTER TABLE accounts ADD COLUMN custom_classification_prompt TEXT"))
             print("Added custom_classification_prompt")
        except Exception as e:
            print(f"custom_classification_prompt might already exist: {e}")

        try:
             await conn.execute(text("ALTER TABLE accounts ADD COLUMN custom_review_prompt TEXT"))
             print("Added custom_review_prompt")
        except Exception as e:
            print(f"custom_review_prompt might already exist: {e}")

    print("Migration complete.")

if __name__ == "__main__":
    asyncio.run(migrate())
