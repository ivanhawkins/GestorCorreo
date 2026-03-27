"""
Migration script to add is_deleted column to messages table.
This enables soft delete functionality.
"""
import sqlite3
import os

# Get database path
db_path = os.path.join(os.path.dirname(__file__), "mail_manager.db")

if not os.path.exists(db_path):
    print(f"❌ Database not found at: {db_path}")
    exit(1)

print(f"📂 Database: {db_path}")
print("🔧 Adding is_deleted column to messages table...")

try:
    # Connect to database
    conn = sqlite3.connect(db_path)
    cursor = conn.cursor()
    
    # Check if column already exists
    cursor.execute("PRAGMA table_info(messages)")
    columns = [col[1] for col in cursor.fetchall()]
    
    if "is_deleted" in columns:
        print("✅ Column 'is_deleted' already exists. No migration needed.")
    else:
        # Add the column
        cursor.execute("""
            ALTER TABLE messages 
            ADD COLUMN is_deleted BOOLEAN DEFAULT 0
        """)
        
        conn.commit()
        print("✅ Successfully added 'is_deleted' column to messages table")
        print("   Default value: FALSE (0)")
    
    # Verify the change
    cursor.execute("PRAGMA table_info(messages)")
    columns = cursor.fetchall()
    print(f"\n📋 Messages table now has {len(columns)} columns:")
    for col in columns:
        print(f"   - {col[1]} ({col[2]})")
    
    conn.close()
    print("\n✅ Migration completed successfully!")
    
except sqlite3.Error as e:
    print(f"❌ Database error: {e}")
    exit(1)
except Exception as e:
    print(f"❌ Unexpected error: {e}")
    exit(1)
