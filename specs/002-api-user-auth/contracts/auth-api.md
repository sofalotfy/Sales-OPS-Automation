# Auth Service API Contract

Base URL: `http://auth-service:8001` (internal Docker Compose DNS; also mapped to a host port for operators/debug) · All routes `Content-Type: application/json`.

**Errors** (all endpoints): error responses use JSON `{"detail": "<human-readable message>"}` with the appropriate status code. Invalid inputs return `422`. `401` means unauthenticated (missing/invalid token); `403` means authenticated but not permitted (admin-only routes). Login failures never reveal whether the username or the password was wrong.

**Auth header**: `Authorization: Bearer <token>` — opaque token issued by `POST /auth/login` (see [data-model.md](../data-model.md#entity-tokens)).

## `GET /health`

Liveness probe (constitution Gate II). **Public** — no token.

**Response `200`**

```json
{ "status": "ok" }
```

## `POST /auth/login` — obtain a token (FR-2, public by necessity)

Body: `{"username": "...", "password": "..."}`.

**Response `201`**

```json
{
  "access_token": "<opaque-token, shown only once>",
  "token_type": "bearer",
  "expires_at": "2026-12-05T10:00:00Z"
}
```

`expires_at` = login time + 90 days (clarified FR-5).

**Error cases**

| Code | Condition |
|------|-----------|
| 401 | Unknown username, wrong password, disabled account, or account currently throttled — one generic message, no enumeration. |
| 422 | Missing/blank `username` or `password`; password too short. |
| 429 | Repeated failures within a short window (throttling surfaced as a limit, not a lockout). |

## `POST /auth/logout` — revoke the presented token (FR-6, authenticated)

Requires `Authorization: Bearer <token>`. Revokes exactly the presented token (concurrent sessions from the same user are unaffected).

**Response `200`**

```json
{ "revoked": true }
```

**Errors**: `401` if the token is missing, unknown, expired, already revoked, or belongs to a disabled user.

## `GET /auth/verify` — token introspection for resource services (FR-3, authenticated)

Requires `Authorization: Bearer <token>`. Used by the RAG service (and future services) to authorize each protected request. No caching anywhere in the path so revocation/disablement apply to the very next request (SC-3).

**Response `200`**

```json
{
  "user_id": "uuid",
  "username": "operator-1",
  "role": "user"
}
```

**Errors**: `401` for missing/unknown/expired/revoked tokens or a disabled/absent user. This endpoint never returns role-specific `403`; authorization to a *resource* is the caller's decision based on the returned `role`.

## Admin user management (FR-1, FR-4 — administrator-only)

All admin routes require a valid `admin` token; a valid `user` token gets `403`; no/invalid token gets `401`.

### `POST /auth/users` — create a user

Body: `{"username": "...", "password": "...", "role": "user"}` (`role` defaults to `user`).

**Response `201`**

```json
{ "user_id": "uuid", "username": "operator-2", "role": "user", "is_enabled": true, "created_at": "2026-09-06T10:00:00Z" }
```

**Error cases**

| Code | Condition |
|------|-----------|
| 409 | `username` already exists. |
| 422 | Blank/undersized password or malformed username/role. |

### `GET /auth/users` — list users

Query params: `enabled` (optional boolean filter), `limit` (default 50, max 200), `offset` (default 0).

**Response `200`**

```json
{
  "items": [
    { "user_id": "uuid", "username": "operator-1", "role": "admin", "is_enabled": true, "created_at": "..." }
  ],
  "total": 3,
  "limit": 50,
  "offset": 0
}
```

Password hashes are never returned.

### `PATCH /auth/users/{user_id}` — enable/disable a user (FR-4)

Body: `{"is_enabled": false}` (or `true`). One field is enough for v1; future fields (password change) extend this body.

**Response `200`** — updated user object (as in create).

**Errors**: `404` unknown user; `422` invalid body.

### `DELETE /auth/users/{user_id}` — remove a user (bonus/administrator hygiene)

Cascades to the user's tokens.

**Response `200`**

```json
{ "deleted": true, "user_id": "..." }
```

**Errors**: `404` unknown user. Attempts to delete the last enabled `admin` are refused (`409`) so the system can never lose all administrators (bootstrap safety).