# Quickstart: API User Authentication

**Purpose**: Validate the feature end-to-end after implementation: account provisioning, login issuing a token, header-token authorization gating the RAG API, revocation/disablement taking effect immediately, and the 90-day expiry.

**Contracts**: [auth-api.md](./contracts/auth-api.md) · [rag-auth-guard.md](./contracts/rag-auth-guard.md) · **Data model**: [data-model.md](./data-model.md)

## Prerequisites

- Docker + Docker Compose (stack gets a new `auth-service`; `db` unchanged).
- `auth-service/.env` with `DATABASE_URL=postgresql+psycopg://rag:rag@db:5432/auth`, `AUTH_INITIAL_ADMIN_USERNAME=admin`, `AUTH_INITIAL_ADMIN_PASSWORD=<strong-password>`.
- RAG service starts *after* auth-service is healthy; `AUTH_SERVICE_URL=http://auth-service:8001` in `work-scope-rag/.env`.

## Setup

```bash
docker compose up --build -d
curl -s http://localhost:8001/health   # auth-service: expect {"status":"ok"}
curl -s http://localhost:8000/health   # work-scope-rag: expect {"status":"ok"} (public)
```

First admin is seeded from env on startup only when the `users` table is empty.

## Validation Scenarios (map to spec Acceptance Scenarios)

### 1. Admin login works and issues a token (US-1, US-2)

```bash
curl -s -X POST http://localhost:8001/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"username":"admin","password":"<strong-password>"}'
```

**Expected**: `201` with `access_token` (opaque; shown once), `token_type:"bearer"`, `expires_at` ≈ now + 90 days.

### 2. Protected RAG route requires the token (US-2, FR-3)

```bash
curl -s http://localhost:8000/documents                    # expect 401 {"detail":"Not authenticated."}
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8000/documents
```

**Expected**: no header → `401` and NO operation runs; with a valid `admin` token → `200` (empty list is fine).

### 3. Invalid / garbage token is refused (US-2)

```bash
curl -s -H "Authorization: Bearer not-a-real-token" http://localhost:8000/documents
```

**Expected**: `401`, operation not performed.

### 4. Admin creates a `user`, that user logs in and accesses the RAG API (US-1, FR-1)

```bash
curl -s -X POST http://localhost:8001/auth/users -H "Authorization: Bearer $ADMIN_TOKEN" \
     -H 'Content-Type: application/json' \
     -d '{"username":"svc-reader","password":"<min-12-chars>","role":"user"}'
curl -s -X POST http://localhost:8001/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"username":"svc-reader","password":"<min-12-chars>"}'
curl -s -H "Authorization: Bearer $USER_TOKEN" http://localhost:8000/documents
```

**Expected**: create → `201`; login → `201` + token; RAG call → `200`. Duplicate `svc-reader` create → `409`.

### 5. Disabled user loses access on the very next request (US-3, FR-4, SC-3)

```bash
curl -s -X PATCH http://localhost:8001/auth/users/<user_id> -H "Authorization: Bearer $ADMIN_TOKEN" \
     -H 'Content-Type: application/json' -d '{"is_enabled":false}'
curl -s -H "Authorization: Bearer $USER_TOKEN" http://localhost:8000/documents
curl -s -X POST http://localhost:8001/auth/login \
     -H 'Content-Type: application/json' -d '{"username":"svc-reader","password":"<min-12-chars>"}'
```

**Expected**: PATCH → `200`; RAG call immediately → `401`; login → `401` (disabled).

### 6. Logout revokes the token (US-3, FR-6)

```bash
curl -s -X POST http://localhost:8001/auth/logout -H "Authorization: Bearer $USER_TOKEN"
curl -s -H "Authorization: Bearer $USER_TOKEN" http://localhost:8000/documents
```

**Expected**: logout → `200 {"revoked":true}`; RAG call → `401`.

### 7. Expiry is enforced (FR-5)

Set `AUTH_TOKEN_TTL_DAYS=0`? — no. Instead, override the TTL to e.g. 1 second in dev (`AUTH_TOKEN_TTL_SECONDS=1`), login, wait, call the RAG API.

**Expected**: RAG call after expiry → `401`; fresh login → works again.

### 8. Non-admin cannot manage users (admin-only provisioning, FR-1)

```bash
curl -s -X POST http://localhost:8001/auth/users -H "Authorization: Bearer $USER_TOKEN" \
     -H 'Content-Type: application/json' -d '{"username":"x","password":"123456789012","role":"user"}'
```

**Expected**: `403`.

### 9. Health stays public (FR-8) even with no token on both services

Covered by the Setup step; repeat after a disable/revoke to confirm nothing regressed.

## Success Target (spec Success Criteria)

- Admin provisioning, login, and a protected RAG call work end-to-end in a single session (SC-1).
- Every protected RAG route (`POST/GET/PUT/DELETE /documents`, `POST /query`) has been observed returning `401` without a valid token and `200`/its normal result with one (SC-2) — spot-check all five at least once.
- Disable-then-call (scenario 5) and logout-then-call (scenario 6) each deny the *immediately following* request (SC-3).
- 5+ consecutive wrong passwords on one account are rejected with the generic message and escalating waits, then the account succeeds with the right password after the cooldown (SC-4, FR-7).
- `GET /health` returns `200` on both services with no token throughout (SC-5).

## Notes

- Scenarios 1–8 exercise the exact interfaces the RAG service and future services consume; no UI.
- Concurrent-load and contract suites live in `tests/` (`test_contract_auth.py`, integration suites) rather than here.
- `AUTH_TOKEN_EXPIRY` is configurable to a seconds-scale value only in dev; production default is 90 days.