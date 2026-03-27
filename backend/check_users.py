import sqlite3
import os

db_path1 = r"d:\proyectos\programasivan\GestorCorreo\data\mail.db"
db_path2 = r"d:\proyectos\programasivan\GestorCorreo\backend\mail_manager.db"

for path in [db_path1, db_path2]:
    print(f"\nChecking DB: {path}")
    if not os.path.exists(path):
        print("Not found.")
        continue
    try:
        conn = sqlite3.connect(path)
        cur = conn.cursor()
        cur.execute("SELECT name FROM sqlite_master WHERE type='table';")
        tables = cur.fetchall()
        print("Tables:")
        for t in tables:
            print(f" - {t[0]}")
            if t[0] == 'users':
                cur.execute("SELECT id, username, is_active, is_admin FROM users;")
                users = cur.fetchall()
                print(f"   Users: {users}")
        conn.close()
    except Exception as e:
        print(f"Error: {e}")
