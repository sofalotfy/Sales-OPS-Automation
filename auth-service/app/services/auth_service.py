from datetime import timedelta

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.config import settings
from app.core.errors import too_many_requests, unauthorized
from app.core.security import (
    generate_token,
    hash_password,
    token_hash,
    verify_password,
)
from app.models.token import Token
from app.models.user import User, utcnow

# Used to equalize Argon2 timing when credentials do not match any account so
# login latency does not leak whether a username exists.
_DUMMY_HASH = hash_password("dummy-password-for-timing-equality-9zz")


def _backoff_seconds(attempts: int) -> int:
    """Progressive exponential backoff (FR-7): 1s, 2s, 4s, ... capped at 15 min."""

    return min(2 ** (attempts - 1), 900)


async def login(db: AsyncSession, username: str, password: str) -> dict:
    now = utcnow()
    result = await db.execute(select(User).where(User.username == username))
    user: User | None = result.scalar_one_or_none()

    if user is None:
        verify_password(password, _DUMMY_HASH)
        raise unauthorized("Invalid username or password.")

    if user.locked_until is not None and user.locked_until > now:
        raise too_many_requests("Too many attempts. Try again later.")

    if not user.is_enabled:
        verify_password(password, _DUMMY_HASH)
        raise unauthorized("Invalid username or password.")

    if not verify_password(password, user.password_hash):
        user.failed_attempts += 1
        user.locked_until = now + timedelta(
            seconds=_backoff_seconds(user.failed_attempts)
        )
        await db.commit()
        raise unauthorized("Invalid username or password.")

    user.failed_attempts = 0
    user.locked_until = None
    expires_at = now + timedelta(seconds=settings.auth_token_expiry_seconds)

    raw_token = generate_token()
    db.add(
        Token(
            user_id=user.id,
            token_hash=token_hash(raw_token),
            expires_at=expires_at,
        )
    )
    await db.commit()

    return {
        "access_token": raw_token,
        "token_type": "bearer",
        "expires_at": expires_at.isoformat(),
    }


async def logout(db: AsyncSession, raw_token: str) -> None:
    """Revoke exactly the presented token (FR-6). Concurrent sessions unaffected."""

    hashed = token_hash(raw_token)
    result = await db.execute(select(Token).where(Token.token_hash == hashed))
    token = result.scalar_one_or_none()

    now = utcnow()
    if token is None or token.revoked_at is not None or token.expires_at <= now:
        raise unauthorized("Not authenticated.")

    token.revoked_at = now
    await db.commit()


async def verify_token(db: AsyncSession, raw_token: str) -> dict | None:
    """Validate a bearer token; return the owning user's context or None.

    A token is valid only if it exists, is not revoked, is not expired, and
    belongs to an existing enabled user (FR-3, SC-3 — checked on every request).
    """

    now = utcnow()
    hashed = token_hash(raw_token)
    result = await db.execute(select(Token).where(Token.token_hash == hashed))
    token: Token | None = result.scalar_one_or_none()

    if token is None or token.revoked_at is not None or token.expires_at <= now:
        return None

    user = await db.get(User, token.user_id)
    if user is None or not user.is_enabled:
        return None

    token.last_used_at = now
    await db.commit()

    return {"user_id": user.id, "username": user.username, "role": user.role}