import sqlite3
import os

db_path = r"d:\proyectos\programasivan\GestorCorreo\data\mail.db"
with open("final_check.txt", "w") as f:
    if os.path.exists(db_path):
        conn = sqlite3.connect(db_path)
        cur = conn.cursor()
        cur.execute("SELECT name FROM sqlite_master WHERE type='table';")
        tables = cur.fetchall()
        f.write(f"Tables: {tables}\n")
        if ('users',) in tables:
            cur.execute("SELECT id, username, is_admin FROM users;")
            users = cur.fetchall()
            f.write(f"Users: {users}\n")
        conn.close()
    else:
        f.write("DB not found\n")
