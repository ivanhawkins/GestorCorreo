import asyncio
from app.database import engine
from sqlalchemy import text

async def add_column():
    async with engine.begin() as conn:
        try:
            await conn.execute(text("ALTER TABLE accounts ADD COLUMN owner_profile TEXT"))
            print("Column 'owner_profile' added successfully.")
        except Exception as e:
            if "duplicate column" in str(e) or "no such table" in str(e): # SQLite might behave differently
                 print(f"Result: {e}")
            else:
                 print(f"Error adding column: {e}")

if __name__ == "__main__":
    asyncio.run(add_column())
