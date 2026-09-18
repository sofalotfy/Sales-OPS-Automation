import logging

from fastapi import FastAPI
from sqlalchemy import select

from app.core.config import settings
from app.core.db import engine, async_session_factory
from app.core.security import hash_password
from app.models import Base
from app.models.user import User

logger = logging.getLogger(__name__)


async def init_db() -> None:
    """Create all auth tables (v1 bootstrap, mirrors the RAG service pattern)."""
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    logger.info("Auth database schema initialized")


async def seed_admin() -> None:
    """Seed the first administrator from env when no users exist.

    Administrator-only provisioning (clarified) needs a bootstrap account. If the
    table is empty and no admin credentials are configured, refuse — a startup
    without any admin would leave the system unusable.
    """
    async with async_session_factory() as session:
        result = await session.execute(select(User.id).limit(1))
        if result.scalar_one_or_none() is not None:
            return

        username = settings.auth_initial_admin_username
        password = settings.auth_initial_admin_password
        if not password:
            raise RuntimeError(
                "users table is empty and AUTH_INITIAL_ADMIN_PASSWORD is not set. "
                "Set it (and optionally AUTH_INITIAL_ADMIN_USERNAME) to bootstrap "
                "the first administrator."
            )

        admin = User(
            username=username,
            password_hash=hash_password(password),
            role="admin",
            is_enabled=True,
        )
        session.add(admin)
        await session.commit()
        logger.info("Seeded bootstrap administrator %r", username)


async def lifespan(app: FastAPI):
    await init_db()
    await seed_admin()
    yield