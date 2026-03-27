"""
Recrear la base de datos con el esquema correcto.
"""
import os
import shutil

db_path = "mail_manager.db"

print(f"📂 Database: {db_path}")

# Hacer backup
if os.path.exists(db_path):
    backup_path = f"{db_path}.backup"
    shutil.copy(db_path, backup_path)
    print(f"✅ Backup creado: {backup_path}")
    
    # Eliminar base de datos actual
    os.remove(db_path)
    print(f"🗑️  Base de datos eliminada")

print("✅ Listo. Reinicia el backend para que se cree la nueva base de datos.")
