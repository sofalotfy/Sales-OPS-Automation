from fastapi import Depends, Request
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.db import async_session_factory
from app.core.errors import forbidden, unauthorized
from app.core.security import token_hash
from app.models.token import Token
from app.models.user import User
from app.models.user import utcnow

bearer = HTTPBearer(auto_error=False)


async def get_db() -> AsyncSession:
    async with async_session_factory() as session:
        yield session


async def get_current_user(
    request: Request,
    db: AsyncSession = Depends(get_db),
    credentials: HTTPAuthorizationCredentials | None = Depends(bearer),
) -> User:
    if credentials is None or credentials.scheme.lower() != "bearer":
        raise unauthorized("Not authenticated.")

    hashed = token_hash(credentials.credentials)

    from sqlalchemy import select

    result = await db.execute(select(Token).where(Token.token_hash == hashed))
    token = result.scalar_one_or_none()

    now = utcnow()
    if token is None or token.revoked_at is not None or token.expires_at <= now:
        raise unauthorized("Not authenticated.")

    user = await db.get(User, token.user_id)
    if user is None or not user.is_enabled:
        raise unauthorized("Not authenticated.")

    token.last_used_at = now
    await db.commit()

    return user


async def require_admin(user: User = Depends(get_current_user)) -> User:
    if user.role != "admin":
        raise forbidden("Not enough permissions.")
    return user