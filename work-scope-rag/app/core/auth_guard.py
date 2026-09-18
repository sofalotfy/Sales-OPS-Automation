import httpx
from fastapi import Depends, HTTPException
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app.core.config import settings

_bearer = HTTPBearer(auto_error=False)


async def require_valid_token(
    credentials: HTTPAuthorizationCredentials | None = Depends(_bearer),
) -> dict:
    """FastAPI dependency gating protected RAG routes.

    Extracts the caller's `Authorization: Bearer <token>` and introspects it
    against `auth-service GET /auth/verify` (contract: rag-auth-guard.md).
    - Missing/invalid/expired/revoked token  -> 401, operation NOT performed.
    - auth-service unreachable/misbehaving   -> 503 (fail closed).
    Verification is un-cached per request so revocation and disablement take
    effect on the very next call (spec SC-3).
    """

    if credentials is None or credentials.scheme.lower() != "bearer":
        raise HTTPException(status_code=401, detail="Not authenticated.")

    url = f"{settings.auth_service_url.rstrip('/')}/auth/verify"
    headers = {"Authorization": f"Bearer {credentials.credentials}"}

    try:
        async with httpx.AsyncClient(timeout=3.0) as client:
            response = await client.get(url, headers=headers)
    except httpx.HTTPError:
        raise HTTPException(
            status_code=503, detail="Authorization service unavailable."
        )

    if response.status_code == 401:
        raise HTTPException(status_code=401, detail="Not authenticated.")
    if response.status_code != 200:
        raise HTTPException(
            status_code=503, detail="Authorization service unavailable."
        )

    return response.json()