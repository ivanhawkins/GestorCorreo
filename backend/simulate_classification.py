import asyncio
import sys
import os
from pathlib import Path

# Add the project root to the Python path
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from app.database import AsyncSessionLocal
from app.models import Message, Classification, Account
from app.services.scheduler import run_classification
from sqlalchemy import select

async def simulate_classification():
    print("Iniciando simulacion de clasificacion...")
    async with AsyncSessionLocal() as db:
        # 1. Obtener la primera cuenta
        result = await db.execute(select(Account).limit(1))
        account = result.scalar_one_or_none()
        
        if not account:
            print("No hay cuentas configuradas.")
            return

        print(f"Cuenta seleccionada: {account.email_address}")

        # 2. Buscar correos SIN clasificar
        result = await db.execute(
            select(Message.id)
            .outerjoin(Classification, Message.id == Classification.message_id)
            .where(Classification.id.is_(None))
            .where(Message.account_id == account.id)
            .limit(5) # Solo 5 para la simulacion
        )
        unclassified_ids = result.scalars().all()

        if not unclassified_ids:
            print("¡Todos los correos están clasificados! No hay pendientes.")
            return

        print(f"Encontrados correos sin clasificar. ID de prueba: {unclassified_ids}")
        print("Enviando a run_classification()...")

        # 3. Forzar clasificacion
        try:
            count = await run_classification(db, account, unclassified_ids)
            print(f"¡Exito! Se han clasificado {count} correos correctamente.")
            
            # 4. Verificar resultado final
            result = await db.execute(
                select(Classification).where(Classification.message_id.in_(unclassified_ids))
            )
            classifications = result.scalars().all()
            for c in classifications:
                print(f" -> Mensaje {c.message_id[:8]}... clasificado como [{c.final_label}] (Decidido por: {c.decided_by})")
                
        except Exception as e:
            print(f"Error durante la clasificacion: {e}")

if __name__ == "__main__":
    asyncio.run(simulate_classification())
