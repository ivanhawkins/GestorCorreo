import asyncio
from sqlalchemy import text
from app.database import engine

async def migrate():
    async with engine.begin() as conn:
        print("Adding deleted_at column to users table...")
        try:
            await conn.execute(text("ALTER TABLE users ADD COLUMN deleted_at DATETIME DEFAULT NULL"))
            print("Column deleted_at added.")
        except Exception as e:
            print(f"Error adding column (might already exist): {e}")

if __name__ == "__main__":
    asyncio.run(migrate())
