from fastapi import APIRouter, Depends, status
from fastapi.security import HTTPAuthorizationCredentials
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.dependencies import bearer, get_current_user, get_db
from app.models.user import User
from app.schemas.auth import (
    LoginRequest,
    LoginResponse,
    LogoutResponse,
    VerifyResponse,
)
from app.services import auth_service

router = APIRouter(tags=["auth"])


@router.post(
    "/auth/login",
    response_model=LoginResponse,
    status_code=status.HTTP_201_CREATED,
)
async def login(body: LoginRequest, db: AsyncSession = Depends(get_db)):
    return await auth_service.login(
        db=db, username=body.username, password=body.password
    )


@router.get("/auth/verify", response_model=VerifyResponse)
async def verify(user: User = Depends(get_current_user)):
    return VerifyResponse(
        user_id=user.id, username=user.username, role=user.role
    )


@router.post("/auth/logout", response_model=LogoutResponse)
async def logout(
    credentials: HTTPAuthorizationCredentials | None = Depends(bearer),
    db: AsyncSession = Depends(get_db),
    user: User = Depends(get_current_user),
):
    await auth_service.logout(db=db, raw_token=credentials.credentials)
    return LogoutResponse()