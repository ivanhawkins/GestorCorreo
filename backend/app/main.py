"""
FastAPI application entry point for Mail Manager.
"""
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from contextlib import asynccontextmanager

from app.database import init_db
from app.utils.logging_config import setup_logging


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Initialize database and logging on startup."""
    # Setup logging
    setup_logging(log_level="INFO", log_to_file=True)
    
    # Initialize database
    await init_db()

    # Initialize default account from env
    from app.utils.initial_data import init_default_account
    from app.database import AsyncSessionLocal
    
    async with AsyncSessionLocal() as db:
        await init_default_account(db)

    # Start Background Scheduler
    from app.services.scheduler import start_scheduler, shutdown_scheduler
    start_scheduler()

    yield

    shutdown_scheduler()


app = FastAPI(
    title="Mail Manager API",
    description="Local email management with AI classification",
    version="0.1.0",
    lifespan=lifespan
)

# CORS for local development
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:5173", "http://localhost:5174", "tauri://localhost"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/")
async def root():
    """Health check endpoint."""
    return {
        "status": "ok",
        "service": "Mail Manager API",
        "version": "0.1.0"
    }


@app.get("/health")
async def health():
    """Detailed health check."""
    return {
        "status": "healthy",
        "database": "connected",
        "ollama": "not_checked"  # TODO: Check Ollama connection
    }


# Import routers
from app.routers import accounts, messages, sync, attachments, classify, whitelist, send, ai, categories, auth, users, ai_config, rules

app.include_router(accounts.router, prefix="/api/accounts", tags=["accounts"])
app.include_router(messages.router, prefix="/api/messages", tags=["messages"])
app.include_router(sync.router, prefix="/api/sync", tags=["sync"])
app.include_router(attachments.router, prefix="/api/attachments", tags=["attachments"])
app.include_router(classify.router, prefix="/api/classify", tags=["classify"])
app.include_router(whitelist.router, prefix="/api/whitelist", tags=["whitelist"])
app.include_router(send.router, prefix="/api/send", tags=["send"])
app.include_router(ai.router, prefix="/api/ai", tags=["ai"])
app.include_router(categories.router, prefix="/api/categories", tags=["categories"])
from app.routers import system
app.include_router(system.router, prefix="/api/system", tags=["system"])
app.include_router(auth.router, prefix="/api/auth", tags=["auth"])
app.include_router(users.router, prefix="/api/users", tags=["users"])
app.include_router(ai_config.router, prefix="/api/ai-config", tags=["ai-config"])
app.include_router(rules.router, prefix="/api/rules", tags=["rules"])

if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app.main:app", host="0.0.0.0", port=8000, reload=True)

