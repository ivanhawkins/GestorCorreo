import asyncio
import sys
import os

sys.path.append(os.path.join(os.path.dirname(__file__), "app"))

from app.database import engine
from sqlalchemy import text

async def apply_migration():
    with open("create_sender_rules_table.sql", "r") as f:
        sql = f.read()
    
    async with engine.begin() as conn:
        for statement in sql.split(';'):
            statement = statement.strip()
            if statement:
                print(f"Executing: {statement[:50]}...")
                await conn.execute(text(statement))
    
    print("Migration applied successfully!")

if __name__ == "__main__":
    if sys.platform == 'win32':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    asyncio.run(apply_migration())
