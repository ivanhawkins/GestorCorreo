import asyncio
import os
import sys
from pathlib import Path
from sqlalchemy.ext.asyncio import create_async_engine, AsyncSession, async_sessionmaker
from sqlalchemy import text

db_path = r"d:\proyectos\programasivan\GestorCorreo\data\mail.db"
# ensure dir exists
os.makedirs(os.path.dirname(db_path), exist_ok=True)

from app.database import engine, Base, init_db
from app.auth import get_password_hash
from app.models import User

async def setup():
    print("Initializing Database...")
    await init_db()
    
    print("Checking for admin user...")
    async with AsyncSession(engine) as session:
        result = await session.execute(text("SELECT id FROM users WHERE username = 'admin'"))
        row = result.fetchone()
        
        if not row:
            print("Creating default admin user...")
            pwd = get_password_hash("admin")
            await session.execute(text(
                "INSERT INTO users (username, password_hash, is_admin, is_active) VALUES (:u, :p, 1, 1)"
            ), {"u": "admin", "p": pwd})
            await session.commit()
            print("Admin user created with password 'admin'.")
        else:
            print("Admin user already exists.")

if __name__ == "__main__":
    asyncio.run(setup())
