"""
Eliminar columna is_deleted de la tabla messages.
"""
import sqlite3
import os

db_path = os.path.join(os.path.dirname(__file__), "mail_manager.db")

print(f"📂 Database: {db_path}")
print("🔧 Eliminando columna is_deleted...")

try:
    conn = sqlite3.connect(db_path)
    cursor = conn.cursor()
    
    # SQLite no soporta DROP COLUMN directamente, necesitamos recrear la tabla
    # Primero verificamos que la columna existe
    cursor.execute("PRAGMA table_info(messages)")
    columns = cursor.fetchall()
    
    if not any(col[1] == "is_deleted" for col in columns):
        print("✅ La columna 'is_deleted' no existe, no hay nada que hacer")
        conn.close()
        exit(0)
    
    # Crear tabla temporal sin is_deleted
    cursor.execute("""
        CREATE TABLE messages_new (
            id VARCHAR PRIMARY KEY,
            account_id INTEGER NOT NULL,
            imap_uid INTEGER NOT NULL,
            message_id VARCHAR NOT NULL,
            thread_id VARCHAR,
            from_name VARCHAR,
            from_email VARCHAR NOT NULL,
            to_addresses TEXT,
            cc_addresses TEXT,
            bcc_addresses TEXT,
            subject VARCHAR,
            date TIMESTAMP,
            snippet TEXT,
            body_text TEXT,
            body_html TEXT,
            has_attachments BOOLEAN DEFAULT 0,
            is_read BOOLEAN DEFAULT 0,
            is_starred BOOLEAN DEFAULT 0,
            folder VARCHAR DEFAULT 'Inbox',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
        )
    """)
    
    # Copiar datos
    cursor.execute("""
        INSERT INTO messages_new 
        SELECT id, account_id, imap_uid, message_id, thread_id, from_name, from_email,
               to_addresses, cc_addresses, bcc_addresses, subject, date, snippet,
               body_text, body_html, has_attachments, is_read, is_starred, folder, created_at
        FROM messages
    """)
    
    # Eliminar tabla vieja y renombrar
    cursor.execute("DROP TABLE messages")
    cursor.execute("ALTER TABLE messages_new RENAME TO messages")
    
    # Recrear índices
    cursor.execute("CREATE INDEX idx_messages_account ON messages(account_id)")
    cursor.execute("CREATE INDEX idx_messages_date ON messages(date DESC)")
    cursor.execute("CREATE INDEX idx_messages_thread ON messages(thread_id)")
    
    conn.commit()
    conn.close()
    
    print("✅ Columna 'is_deleted' eliminada exitosamente")
    print("✅ Migración completada")
    
except Exception as e:
    print(f"❌ Error: {e}")
    import traceback
    traceback.print_exc()
    exit(1)
