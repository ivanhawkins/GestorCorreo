import asyncio
import sys
import os

# Add backend to path
sys.path.append(os.path.join(os.getcwd(), 'backend'))

from app.services.imap_service import sync_account_messages

async def run_sync():
    # Use account ID 4 as seen in previous logs
    account_id = 4
    print(f"Triggering sync for account {account_id}...")
    try:
        async for progress in sync_account_messages(account_id):
            print(progress)
            # Break after a few messages to avoid long run, we just want to see the start logic debug output
            if progress.get('status') == 'downloading' and progress.get('current', 0) > 5:
                print("Stopping sync for debug check...")
                break
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    if os.name == 'nt':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    asyncio.run(run_sync())
