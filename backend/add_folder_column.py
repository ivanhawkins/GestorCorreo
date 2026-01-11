"""
Agregar columna folder a la tabla messages.
"""
import sqlite3
import os

db_path = os.path.join(os.path.dirname(__file__), "mail_manager.db")

print(f"📂 Database: {db_path}")
print("🔧 Agregando columna folder...")

try:
    conn = sqlite3.connect(db_path)
    cursor = conn.cursor()
    
    # Verificar si la columna ya existe
    cursor.execute("PRAGMA table_info(messages)")
    columns = [col[1] for col in cursor.fetchall()]
    
    if "folder" in columns:
        print("✅ La columna 'folder' ya existe")
    else:
        # Agregar columna
        cursor.execute("ALTER TABLE messages ADD COLUMN folder VARCHAR DEFAULT 'Inbox'")
        conn.commit()
        print("✅ Columna 'folder' agregada exitosamente")
    
    conn.close()
    print("✅ Migración completada")
    
except Exception as e:
    print(f"❌ Error: {e}")
    exit(1)
