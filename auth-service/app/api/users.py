import uuid

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.dependencies import get_db, require_admin
from app.core.errors import not_found
from app.schemas.user import (
    DeleteResponse,
    UserCreate,
    UserListResponse,
    UserOut,
    UserUpdate,
)
from app.services import user_service

router = APIRouter(tags=["users"])


def _require_valid_id(user_id: str) -> str:
    try:
        uuid.UUID(user_id)
    except ValueError:
        raise not_found("User not found.")
    return user_id


@router.post(
    "/auth/users",
    response_model=UserOut,
    status_code=status.HTTP_201_CREATED,
)
async def create_user(
    body: UserCreate,
    db: AsyncSession = Depends(get_db),
    _: object = Depends(require_admin),
):
    user = await user_service.create_user(
        db=db, username=body.username, password=body.password, role=body.role
    )
    return UserOut(**user.to_dict())


@router.get("/auth/users", response_model=UserListResponse)
async def list_users(
    enabled: bool | None = Query(default=None),
    limit: int = Query(default=50, ge=1, le=200),
    offset: int = Query(default=0, ge=0),
    db: AsyncSession = Depends(get_db),
    _: object = Depends(require_admin),
):
    return await user_service.list_users(
        db=db, enabled=enabled, limit=limit, offset=offset
    )


@router.patch("/auth/users/{user_id}", response_model=UserOut)
async def update_user(
    user_id: str,
    body: UserUpdate,
    db: AsyncSession = Depends(get_db),
    _: object = Depends(require_admin),
):
    _require_valid_id(user_id)
    user = await user_service.set_enabled(
        db=db, user_id=user_id, is_enabled=body.is_enabled
    )
    return UserOut(**user.to_dict())


@router.delete("/auth/users/{user_id}", response_model=DeleteResponse)
async def delete_user(
    user_id: str,
    db: AsyncSession = Depends(get_db),
    _: object = Depends(require_admin),
):
    _require_valid_id(user_id)
    await user_service.delete_user(db=db, user_id=user_id)
    return DeleteResponse(user_id=user_id)