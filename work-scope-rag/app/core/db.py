from sqlalchemy.ext.asyncio import (
    async_sessionmaker,
    create_async_engine,
)

from app.core.config import settings

engine = create_async_engine(
    settings.database_url,
    echo=False,
    pool_pre_ping=True,
    pool_size=20,
    max_overflow=60,
    pool_timeout=60,
)

async_session_factory = async_sessionmaker(
    bind=engine,
    expire_on_commit=False,
)