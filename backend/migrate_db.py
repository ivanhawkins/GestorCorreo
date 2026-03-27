"""
Script to migrate database to add new fields to accounts table.
This will add ssl_verify, connection_timeout, and last_sync_error fields.
"""
import asyncio
import sys
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent))

from app.database import engine, Base
from app.models import Account, Message, Attachment, Classification, ServiceWhitelist, AuditLog
from sqlalchemy import text


async def migrate_database():
    """Add new fields to accounts table."""
    print("Starting database migration...")
    
    async with engine.begin() as conn:
        # Check if columns already exist
        result = await conn.execute(text("PRAGMA table_info(accounts)"))
        columns = [row[1] for row in result.fetchall()]
        
        print(f"Current columns in accounts table: {columns}")
        
        # Add ssl_verify column if it doesn't exist
        if 'ssl_verify' not in columns:
            print("Adding ssl_verify column...")
            await conn.execute(text(
                "ALTER TABLE accounts ADD COLUMN ssl_verify BOOLEAN DEFAULT 1"
            ))
            print("✓ Added ssl_verify column")
        else:
            print("✓ ssl_verify column already exists")
        
        # Add connection_timeout column if it doesn't exist
        if 'connection_timeout' not in columns:
            print("Adding connection_timeout column...")
            await conn.execute(text(
                "ALTER TABLE accounts ADD COLUMN connection_timeout INTEGER DEFAULT 30"
            ))
            print("✓ Added connection_timeout column")
        else:
            print("✓ connection_timeout column already exists")
        
        # Add last_sync_error column if it doesn't exist
        if 'last_sync_error' not in columns:
            print("Adding last_sync_error column...")
            await conn.execute(text(
                "ALTER TABLE accounts ADD COLUMN last_sync_error TEXT"
            ))
            print("✓ Added last_sync_error column")
        else:
            print("✓ last_sync_error column already exists")
    
    print("\n✓ Database migration completed successfully!")


async def recreate_database():
    """Recreate database from scratch (WARNING: This will delete all data!)."""
    print("WARNING: This will delete all existing data!")
    response = input("Are you sure you want to recreate the database? (yes/no): ")
    
    if response.lower() != 'yes':
        print("Operation cancelled.")
        return
    
    print("\nRecreating database...")
    
    async with engine.begin() as conn:
        # Drop all tables
        await conn.run_sync(Base.metadata.drop_all)
        print("✓ Dropped all tables")
        
        # Create all tables
        await conn.run_sync(Base.metadata.create_all)
        print("✓ Created all tables")
    
    print("\n✓ Database recreated successfully!")


async def main():
    """Main function."""
    print("="*60)
    print("Mail Manager - Database Migration Tool")
    print("="*60)
    print("\nOptions:")
    print("1. Migrate existing database (add new columns)")
    print("2. Recreate database from scratch (WARNING: deletes all data)")
    print("3. Exit")
    
    choice = input("\nSelect option (1-3): ").strip()
    
    if choice == "1":
        await migrate_database()
    elif choice == "2":
        await recreate_database()
    elif choice == "3":
        print("Exiting...")
    else:
        print("Invalid option")


if __name__ == "__main__":
    asyncio.run(main())
