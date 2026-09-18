import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI
from sqlalchemy import text

from app.core.db import engine
from app.models import Base

logger = logging.getLogger(__name__)


async def init_db() -> None:
    """Register the pgvector extension and create all tables (v1 bootstrap).

    New tables/columns are handled by ``create_all`` for fresh databases and
    by the idempotent ALTERs below for databases that already exist (additive
    original-file columns introduced after initial bootstrap).
    """
    async with engine.begin() as conn:
        await conn.execute(text("CREATE EXTENSION IF NOT EXISTS vector"))
        await conn.run_sync(Base.metadata.create_all)
        await conn.execute(
            text("ALTER TABLE documents ADD COLUMN IF NOT EXISTS original_data BYTEA")
        )
        await conn.execute(
            text(
                "ALTER TABLE documents ADD COLUMN IF NOT EXISTS "
                "original_filename VARCHAR(255)"
            )
        )
    logger.info("Database schema initialized")


@asynccontextmanager
async def lifespan(app: FastAPI):
    await init_db()
    yield