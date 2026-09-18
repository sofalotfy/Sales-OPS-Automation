import os
import sys
from pathlib import Path

# Point the app at the dedicated test database BEFORE the app modules are
# imported below: app.core.config builds its Settings singleton at import time.
# Integration/contract tests run against `auth_test` so test lifecycle never
# touches the live `auth` database (same pattern as rag_test in db/init.sql).
os.environ["DATABASE_URL"] = "postgresql+psycopg://rag:rag@localhost:5432/auth_test"
os.environ["AUTH_INITIAL_ADMIN_USERNAME"] = "admin"
os.environ["AUTH_INITIAL_ADMIN_PASSWORD"] = "test-admin-password"
os.environ["AUTH_TOKEN_EXPIRY_SECONDS"] = "3600"

# Make `app` importable from the tests directory regardless of the cwd.
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import pytest  # noqa: E402


@pytest.fixture(scope="session")
def test_settings():
    from app.core.config import settings

    assert settings.database_url.endswith("/auth_test"), "tests must run on auth_test"
    return settings