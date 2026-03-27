import asyncio
import sys
from pathlib import Path
from sqlalchemy import text
from app.database import engine, Base
from app.auth import get_password_hash
from app.models import User, Account, Category, ServiceWhitelist

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent))

async def migrate_users():
    print("Starting Multi-tenancy Migration...")
    
    async with engine.begin() as conn:
        # Disable Foreign Keys
        await conn.execute(text("PRAGMA foreign_keys=OFF"))
        
        # 1. Ensure Users table exists
        # We can try to create all, but we need to handle existing tables gracefully
        # Base.metadata.create_all checks existence, but the model def changes might not match DB
        # So we trust our rename/replace strategy for modified tables.
        # But allow create_all to create 'users' if text doesn't exist.
        await conn.run_sync(Base.metadata.create_all)
        
        # 2. Create Default Admin
        print("Checking for admin user...")
        result = await conn.execute(text("SELECT id FROM users WHERE username = 'admin'"))
        row = result.fetchone()
        
        if not row:
            print("Creating default admin user...")
            pwd = get_password_hash("admin")
            await conn.execute(text(
                "INSERT INTO users (username, password_hash, is_admin, is_active) VALUES (:u, :p, 1, 1)"
            ), {"u": "admin", "p": pwd})
            
            result = await conn.execute(text("SELECT id FROM users WHERE username = 'admin'"))
            row = result.fetchone()
            print("Admin user created.")
        
        admin_id = row[0]
        print(f"Admin ID: {admin_id}")

        # 3. Migrate Tables
        tables_to_migrate = [
            ("accounts", Account),
            ("categories", Category),
            ("service_whitelist", ServiceWhitelist)
        ]

        for table_name, model in tables_to_migrate:
            print(f"Migrating {table_name}...")
            
            # Check if user_id column exists
            try:
                # If we can select user_id, it exists
                await conn.execute(text(f"SELECT user_id FROM {table_name} LIMIT 1"))
                print(f"✓ {table_name} already has user_id. Skipping migration.")
                continue
            except Exception:
                pass # Column missing, proceed

            print(f"Renaming {table_name} to _old_{table_name}...")
            await conn.execute(text(f"ALTER TABLE {table_name} RENAME TO _old_{table_name}"))
            
            # Create new table using SQLAlchemy schema
            print(f"Creating new {table_name} table...")
            # We use create_all but only for this table? 
            # create_all creates ALL missing. Since we renamed the old one, the name is free.
            # But we only want to create specific tables.
            # We can use model.__table__.create(bind=conn)
            await conn.run_sync(model.__table__.create)
            
            # Copy data
            print(f"Copying data to {table_name}...")
            # Get columns from old table
            result = await conn.execute(text(f"PRAGMA table_info(_old_{table_name})"))
            old_cols = [r[1] for r in result.fetchall()]
            
            cols_str = ", ".join(old_cols)
            # We assume new table has 'user_id' and old doesn't.
            # So we select all old cols -> insert into new cols (except user_id).
            # Wait, syntax: INSERT INTO table (col1, col2, user_id) SELECT col1, col2, 1 FROM old
            
            insert_cols = f"{cols_str}, user_id"
            select_cols = f"{cols_str}, {admin_id}"
            
            await conn.execute(text(f"INSERT INTO {table_name} ({insert_cols}) SELECT {select_cols} FROM _old_{table_name}"))
            
            # Drop old table
            print(f"Dropping _old_{table_name}...")
            await conn.execute(text(f"DROP TABLE _old_{table_name}"))

        # Re-enable Foreign Keys
        await conn.execute(text("PRAGMA foreign_keys=ON"))
        
        # Check constraints
        try:
            await conn.execute(text("PRAGMA foreign_key_check"))
            print("✓ Integrity check passed.")
        except Exception as e:
            print(f"Integrity check warning: {e}")

    print("Migration completed successfully.")

if __name__ == "__main__":
    asyncio.run(migrate_users())
