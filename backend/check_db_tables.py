import sqlite3
import os

db_path1 = r"d:\proyectos\programasivan\GestorCorreo\data\mail.db"

with open("db_tables.txt", "w") as f:
    if os.path.exists(db_path1):
        f.write(f"DB exists: {db_path1}\n")
        conn = sqlite3.connect(db_path1)
        cur = conn.cursor()
        cur.execute("SELECT name FROM sqlite_master WHERE type='table';")
        tables = cur.fetchall()
        f.write("Tables:\n")
        for t in tables:
            f.write(f" - {t[0]}\n")
            if t[0] == 'users':
                cur.execute("SELECT id, username, is_active, is_admin FROM users;")
                users = cur.fetchall()
                f.write(f"   Users: {users}\n")
            if t[0] == 'accounts':
                cur.execute("SELECT id, email_address, username FROM accounts;")
                accounts = cur.fetchall()
                f.write(f"   Accounts: {accounts}\n")
        conn.close()
    else:
        f.write(f"Not found: {db_path1}\n")
