import asyncio
from app.database import engine
from sqlalchemy import text

async def add_category_table():
    async with engine.begin() as conn:
        try:
            # Create table
            await conn.execute(text("""
            CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key VARCHAR NOT NULL UNIQUE,
                name VARCHAR NOT NULL,
                description VARCHAR,
                ai_instruction TEXT NOT NULL,
                icon VARCHAR,
                is_system BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
            """))
            print("Table 'categories' created successfully.")
            
            # Seed default categories if empty
            result = await conn.execute(text("SELECT COUNT(*) FROM categories"))
            count = result.scalar()
            
            if count == 0:
                print("Seeding default categories...")
                defaults = [
                    ("Interesantes", "Interesantes", "Potential clients", "Correos con intención real de contratar servicios de Hawkins (presupuestos, propuestas, reuniones).", "⭐", 1),
                    ("SPAM", "SPAM", "Junk mail", "Spam clásico, phishing, y cualquier correo cuyo propósito sea vendernos algo (cold outreach).", "🚫", 1),
                    ("EnCopia", "En Copia", "Internal loop", "Correos con múltiples destinatarios internos @hawkins.es en To o CC.", "📋", 1),
                    ("Servicios", "Servicios", "Notifications", "Notificaciones transaccionales de plataformas (booking, bancos, Amazon).", "🔔", 1)
                ]
                
                for key, name, desc, instr, icon, is_sys in defaults:
                    await conn.execute(text("""
                        INSERT INTO categories (key, name, description, ai_instruction, icon, is_system)
                        VALUES (:key, :name, :desc, :instr, :icon, :is_sys)
                    """), {"key": key, "name": name, "desc": desc, "instr": instr, "icon": icon, "is_sys": is_sys})
                
                print("Default categories seeded.")
            else:
                print("Categories already exist, skipping seed.")
                
        except Exception as e:
            print(f"Error creating categories table: {e}")

if __name__ == "__main__":
    asyncio.run(add_category_table())
