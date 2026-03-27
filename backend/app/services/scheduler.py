"""
Scheduler service for background email synchronization.
Handles sequential processing of accounts to avoid AI saturation.
"""
import asyncio
import logging
from apscheduler.schedulers.asyncio import AsyncIOScheduler
from apscheduler.triggers.cron import CronTrigger
from apscheduler.triggers.interval import IntervalTrigger
from sqlalchemy import select
from datetime import datetime

from app.database import AsyncSessionLocal
from app.models import Account, AuditLog
from app.services.imap_service import sync_account_messages
from app.services.rules_engine import classify_with_rules_and_ai
from app.models import Message, Classification, ServiceWhitelist, Category

logger = logging.getLogger(__name__)

scheduler = AsyncIOScheduler()


async def run_morning_sync_job():
    """
    Job that runs Mon-Fri at 07:00 AM for accounts WITHOUT auto_sync_interval.
    Iterates through all active accounts sequentially.
    """
    logger.info("Starting Morning Sync Job...")
    
    async with AsyncSessionLocal() as db:
        try:
            # Only process accounts that don't have a custom interval
            result = await db.execute(
                select(Account).where(
                    Account.is_active == True,
                    Account.is_deleted == False,
                    (Account.auto_sync_interval == 0) | (Account.auto_sync_interval == None)
                )
            )
            accounts = result.scalars().all()
            
            logger.info(f"Found {len(accounts)} accounts without interval sync to process.")
            
            for i, account in enumerate(accounts):
                logger.info(f"[{i+1}/{len(accounts)}] Processing account: {account.email_address}")
                try:
                    await process_single_account(account.id)
                except Exception as e:
                    logger.error(f"Failed to process account {account.email_address}: {e}")
            
            logger.info("Morning Sync Job Completed.")
            
        except Exception as e:
            logger.error(f"Critical error in run_morning_sync_job: {e}")


async def run_interval_sync_job(account_id: int):
    """
    Job that runs on a per-account interval (auto_sync_interval minutes).
    """
    logger.info(f"Starting interval sync for account_id={account_id}")
    try:
        await process_single_account(account_id)
    except Exception as e:
        logger.error(f"Interval sync failed for account_id={account_id}: {e}")


async def process_single_account(account_id: int):
    """
    Full sync and classify cycle for a single account.
    """
    from app.utils.security import decrypt_password
    
    async with AsyncSessionLocal() as db:
        # Re-fetch account to ensure session attachment
        result = await db.execute(select(Account).where(Account.id == account_id))
        account = result.scalar_one_or_none()
        
        if not account:
            return

        try:
            password = decrypt_password(account.encrypted_password)
        except Exception:
            logger.error(f"Could not decrypt password for {account.email_address}")
            return

        # 1. Sync (Download)
        logger.info(f"Starting IMAP sync for {account.email_address}...")
        sync_result = None
        new_messages_count = 0
        new_message_ids = []

        # Consume generator
        async for progress in sync_account_messages(account, password, db):
            if progress.get('status') in ['success', 'error']:
                sync_result = progress
        
        if not sync_result or sync_result.get('status') == 'error':
            error_msg = sync_result.get('error') if sync_result else "Unknown error"
            logger.error(f"Sync failed for {account.email_address}: {error_msg}")
            await log_audit(db, account.id, "background_sync_failed", error_msg)
            return

        new_messages_count = sync_result.get('new_messages', 0)
        new_message_ids = sync_result.get('new_message_ids', [])
        logger.info(f"Sync finished for {account.email_address}. New messages: {new_messages_count}")

        # 2. Classify (AI)
        if account.auto_classify and new_messages_count > 0:
            logger.info(f"Starting AI classification for {account.email_address} on {len(new_message_ids)} messages...")
            try:
                classified = await run_classification(db, account, new_message_ids)
                logger.info(f"Classified {classified} messages for {account.email_address}")
                await log_audit(db, account.id, "background_sync_success", 
                                f"Downloaded {new_messages_count}, Classified {classified}")
            except Exception as e:
                logger.error(f"Classification failed for {account.email_address}: {e}")
                await log_audit(db, account.id, "background_sync_partial", 
                                f"Downloaded {new_messages_count}, Classification Failed: {e}")
        else:
             await log_audit(db, account.id, "background_sync_success", 
                            f"Downloaded {new_messages_count}, No classification needed")


async def run_classification(db, account, message_ids):
    """
    Classify specific messages. Shared helper used by scheduler and routers.
    """
    if not message_ids:
        return 0
        
    result = await db.execute(select(ServiceWhitelist).where(ServiceWhitelist.is_active == True))
    whitelist_domains = [e.domain_pattern for e in result.scalars().all()]
    
    result = await db.execute(select(Category))
    categories = [{"key": c.key, "ai_instruction": c.ai_instruction} for c in result.scalars().all()]
    
    if not categories:
        # Fallback to default categories if none exist in DB
        categories = [
            {"key": "Interesantes", "ai_instruction": "Correos de personas directas o importantes."},
            {"key": "SPAM", "ai_instruction": "Correos no deseados o publicidad genérica."},
            {"key": "EnCopia", "ai_instruction": "Correos donde solo estamos en CC o informativos."},
            {"key": "Servicios", "ai_instruction": "Notificaciones de servicios, facturas, alertas."}
        ]
        
    result = await db.execute(
        select(Message)
        .outerjoin(Classification, Message.id == Classification.message_id)
        .where(Message.id.in_(message_ids))
        .where(Classification.id.is_(None))  # FIX: must use .is_(None) for proper IS NULL in SQL
    )
    messages_to_classify = result.scalars().all()
    count = 0
    
    for message in messages_to_classify:
        try:
            message_data = {
                "from_name": message.from_name,
                "from_email": message.from_email,
                "to_addresses": message.to_addresses,
                "cc_addresses": message.cc_addresses,
                "subject": message.subject,
                "date": str(message.date),
                "body_text": message.body_text,
                "snippet": message.snippet
            }
            
            classification_result = await classify_with_rules_and_ai(
                message_data, 
                whitelist_domains, 
                categories,
                custom_classification_prompt=account.custom_classification_prompt
            )
            
            if classification_result.get("status") != "error":
                classification = Classification(
                    message_id=message.id,
                    gpt_label=classification_result.get("gpt_label"),
                    gpt_confidence=classification_result.get("gpt_confidence"),
                    gpt_rationale=classification_result.get("gpt_rationale"),
                    qwen_label=classification_result.get("qwen_label"),
                    qwen_confidence=classification_result.get("qwen_confidence"),
                    qwen_rationale=classification_result.get("qwen_rationale"),
                    final_label=classification_result["final_label"],
                    final_reason=classification_result.get("final_reason"),
                    decided_by=classification_result["decided_by"]
                )
                db.add(classification)
                count += 1
        except Exception as e:
            logger.error(f"Error classifying message {message.id}: {e}")
            
    await db.commit()
    return count


async def log_audit(db, account_id, status, error_message=None):
    try:
        import json
        payload_dict = {"detail": error_message, "account_id": account_id}
        audit = AuditLog(
            action="background_sync",
            payload=json.dumps(payload_dict),
            status=status,
            error_message=str(error_message) if error_message else None
        )
        db.add(audit)
        await db.commit()
    except Exception as e:
        logger.error(f"Failed to write audit log: {e}")


async def _schedule_interval_jobs():
    """
    Load all accounts with auto_sync_interval > 0 and register interval jobs.
    Called at startup and can be called again to refresh after account changes.
    """
    async with AsyncSessionLocal() as db:
        result = await db.execute(
            select(Account).where(
                Account.is_active == True,
                Account.is_deleted == False,
                Account.auto_sync_interval > 0
            )
        )
        accounts = result.scalars().all()
        
        for account in accounts:
            job_id = f"interval_sync_{account.id}"
            if scheduler.get_job(job_id):
                scheduler.remove_job(job_id)
            
            scheduler.add_job(
                run_interval_sync_job,
                trigger=IntervalTrigger(minutes=account.auto_sync_interval),
                args=[account.id],
                id=job_id,
                replace_existing=True
            )
            logger.info(
                f"Scheduled interval sync for {account.email_address} "
                f"every {account.auto_sync_interval} min (job: {job_id})"
            )


def reschedule_account_jobs():
    """
    Public function to reschedule after account settings change.
    Call this from account update endpoints.
    """
    asyncio.ensure_future(_schedule_interval_jobs())


def start_scheduler():
    """Start the scheduler with both morning job and per-account interval jobs."""
    # Morning job for accounts without custom interval (Mon-Fri 07:00)
    trigger = CronTrigger(day_of_week='mon-fri', hour=7, minute=0)
    scheduler.add_job(run_morning_sync_job, trigger, id='morning_sync_job', replace_existing=True)
    
    scheduler.start()
    logger.info("Background Scheduler started. Morning job: Mon-Fri 07:00.")
    
    # Schedule per-account interval jobs after startup
    asyncio.ensure_future(_schedule_interval_jobs())


def shutdown_scheduler():
    """Shutdown the scheduler."""
    scheduler.shutdown()
    logger.info("Background Scheduler shutdown.")

