import os

os.environ.setdefault(
    "DATABASE_URL", "postgresql+psycopg://rag:rag@localhost:5432/rag_test"
)

import pytest
import pytest_asyncio
from httpx import ASGITransport, AsyncClient
from sqlalchemy.ext.asyncio import (
    AsyncSession,
    async_sessionmaker,
    create_async_engine,
)

from app.core.config import settings
from app.core.auth_guard import require_valid_token
from app.main import app
from app.models import Base

test_engine = create_async_engine(settings.database_url, echo=False)
test_session_factory = async_sessionmaker(
    bind=test_engine, expire_on_commit=False
)


async def _fake_authenticated():
    """Test-only stand-in for require_valid_token.

    The document/query contract suites exercise retrieval logic, not auth;
    auth behavior itself is validated live against auth-service (quickstart.md).
    """

    return {"user_id": "test-user", "username": "test", "role": "user"}


app.dependency_overrides[require_valid_token] = _fake_authenticated


@pytest_asyncio.fixture(scope="session", autouse=True, loop_scope="session")
async def _schema():
    async with test_engine.begin() as conn:
        await conn.execute(
            __import__("sqlalchemy").text("CREATE EXTENSION IF NOT EXISTS vector")
        )
        await conn.run_sync(Base.metadata.drop_all)
        await conn.run_sync(Base.metadata.create_all)
    yield
    async with test_engine.begin() as conn:
        await conn.run_sync(Base.metadata.drop_all)
    await test_engine.dispose()


@pytest_asyncio.fixture(autouse=True, loop_scope="session")
async def _clean_db_between_tests():
    """Truncate rows after each test so tests never see each other's data."""
    yield
    async with test_engine.begin() as conn:
        await conn.execute(
            __import__("sqlalchemy").text("TRUNCATE documents CASCADE")
        )


@pytest_asyncio.fixture(loop_scope="session")
async def client():
    transport = ASGITransport(app=app)
    async with AsyncClient(
        transport=transport, base_url="http://test"
    ) as c:
        yield c


@pytest_asyncio.fixture
async def db_session():
    async with test_session_factory() as session:
        yield session