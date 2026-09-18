from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.errors import conflict, not_found
from app.core.security import hash_password
from app.models.user import User


async def create_user(
    db: AsyncSession, username: str, password: str, role: str = "user"
) -> User:
    existing = await db.execute(select(User).where(User.username == username))
    if existing.scalar_one_or_none() is not None:
        raise conflict("A user with this username already exists.")

    user = User(
        username=username,
        password_hash=hash_password(password),
        role=role,
        is_enabled=True,
    )
    db.add(user)
    await db.commit()
    await db.refresh(user)
    return user


async def list_users(
    db: AsyncSession,
    enabled: bool | None = None,
    limit: int = 50,
    offset: int = 0,
) -> dict:
    query = select(User)
    if enabled is not None:
        query = query.where(User.is_enabled == enabled)
    total = (await db.execute(select(func.count()).select_from(query.subquery()))).scalar_one()
    rows = (
        await db.execute(
            query.order_by(User.username).limit(limit).offset(offset)
        )
    ).scalars().all()
    return {
        "items": [u.to_dict() for u in rows],
        "total": total,
        "limit": limit,
        "offset": offset,
    }


async def set_enabled(db: AsyncSession, user_id: str, is_enabled: bool) -> User:
    user = await db.get(User, user_id)
    if user is None:
        raise not_found("User not found.")
    user.is_enabled = is_enabled
    await db.commit()
    await db.refresh(user)
    return user


async def delete_user(db: AsyncSession, user_id: str) -> None:
    """Delete a user (cascades to tokens). Refuse deleting the last enabled admin."""

    user = await db.get(User, user_id)
    if user is None:
        raise not_found("User not found.")

    if user.role == "admin" and user.is_enabled:
        admins = (
            await db.execute(
                select(User)
                .where(User.role == "admin", User.is_enabled.is_(True))
                .limit(2)
            )
        ).scalars().all()
        if len(admins) <= 1:
            raise conflict(
                "Cannot delete the last enabled administrator."
            )

    await db.delete(user)
    await db.commit()