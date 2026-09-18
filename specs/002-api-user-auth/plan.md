# Implementation Plan: API User Authentication

**Branch**: `002-api-user-auth` | **Date**: 2026-09-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/002-api-user-auth/spec.md`

## Summary

Add a first-class auth layer over the public API: a new, independently deployable `auth-service` owns the user model (administrator-provisioned accounts) and an opaque, server-side token system. Users log in with credentials and receive a 90-day bearer token; every protected request on the RAG service carries that token in the HTTP `Authorization` header and is enforced per-request via `/auth/verify` introspection (no caching → revocation and disablement take effect on the next request). `GET /health` stays public, everything else is gated. Access control only — no per-user quotas or rate limits (clarified scope).

Research decisions (see [research.md](./research.md)): standalone FastAPI `auth-service`; opaque tokens (32-byte random, SHA-256 stored) with `Bearer` header; Argon2id password hashing; per-account exponential-backoff throttling instead of hard lockout; env-seeded first administrator.

## Technical Context

**Language/Version**: Python 3.12 (matches existing `work-scope-rag/Dockerfile` pattern)

**Primary Dependencies**: fastapi, uvicorn, sqlalchemy (2.0 async), psycopg[binary] (psycopg3), pydantic, pydantic-settings, argon2-cffi (OWASP-baseline Argon2id). RAG service gains no new packages — `httpx` (already present) is used for verification calls.

**Storage**: PostgreSQL on the shared server; new dedicated `auth` database (same `rag` role). Tables: `users`, `tokens`. `auth-service` is the only service touching them (Gate I).

**Testing**: pytest + pytest-asyncio + httpx. New `auth-service/tests` (unit + integration against a `rag_test`-style separate `auth_test` database). RAG contract tests extended with a dependency-injected fake verifier plus an integration test against the real auth-service.

**Target Platform**: Linux, Docker Compose (new `auth-service` image alongside `db` and `work-scope-rag`).

**Project Type**: Two backend web-services (auth-service; plus hardening changes to the existing RAG service).

**Performance Goals**: Login verification budget 100–500 ms (Argon2id); `/auth/verify` introspection < 5 ms on the compose network; protected RAG routes add one local round trip (< 10 ms). Scale is tiny (a handful of users).

**Constraints**: Fail closed — if auth-service is unreachable, RAG protected routes return `503` and do not execute; no caching of verification results (preserves immediate revocation, spec SC-3); no JWT/refresh tokens/MFA/self-service flows (out of clarified scope and spec's out-of-scope list); single shared Postgres.

**Scale/Scope**: A handful of human operators plus one or two internal machine consumers; ~5–20 users; single tenant.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Constitution Principle | Status | Notes |
|------------------------|--------|-------|
| I. Independently Deployable Services | PASS | New `auth-service` is a separate image/service; RAG communicates with it only over HTTP (`/auth/verify`). No shared in-process imports; each service owns its own tables; services never reach into another service's database tables (dedicated `auth` DB). |
| II. API-First, FastAPI, `/health` | PASS | `auth-service` is FastAPI with auto docs and `/health`. RAG keeps its API; protection is additive and contract-documented. |
| III. Human-in-the-Loop for Ambiguity | PASS | No ambiguous classifier behavior added. Accounts are *administrator*-provisioned (human-controlled); login errors are generic (no guessing); grant/revoke decisions stay human. Non-revealing throttling avoids username enumeration. |
| IV. Data Model Is the Source of Truth | PASS | `users`/`tokens` in shared Postgres (`auth` database) — not service memory or env-only. Raw tokens are never persisted; hash-at-rest keeps credentials safe. |
| V. Simplicity & Provisional Scope | PASS | Smallest version serving the spec: opaque self-contained tokens (no JWT/refresh/hybrid), DB-backed per-account backoff (no Redis for auth), no MFA/self-service, no quotas. Bootstrap admin via env seed. Anything not confirmed with the sales team stays out. |
| Tech: Docker Compose orchestration | PASS | `auth-service` added to compose; RAG depends on it (`depends_on: service_healthy`); single `docker compose up`. |
| Tech: Redis-backed task queue | PASS (not used) | Throttling state lives in `users` (shared Postgres) by design — no Redis dependency for auth in v1; Redis remains scoped to the async task queue. |
| Tech: PostgreSQL source of truth | PASS | New `auth` database on the shared Postgres server backs the user model. |

### Post-Design Re-check (Gate: re-evaluated after Phase 1)

Re-checked against the Phase 1 design artifacts (data-model.md, contracts/, quickstart.md):

| Principle | Status | Post-design verification |
|-----------|--------|--------------------------|
| I. Independently Deployable Services | PASS | `auth-service` (Dockerfile, own `auth` DB) deploys independently; RAG→auth is a single HTTP call; no library sharing; `rag-auth-guard.md` documents the boundary. |
| II. API-First, FastAPI, `/health` | PASS | `auth-api.md` defines the whole surface (`/health`, `/auth/login`, `/auth/logout`, `/auth/verify`, admin `/auth/users*`); RAG guard contract is explicit. |
| III. Human-in-the-Loop | PASS | Admin-only provisioning, explicit enable/disable, generic 401/429 login errors (no credential enumeration), fail-closed `503` surfaces infrastructure problems rather than silently allowing. |
| IV. Data Model Source of Truth | PASS | `users` (incl. Argon2id hash, throttle state) + `tokens` (SHA-256 hash, expiry, revocation) live in shared Postgres; data-model.md documents validity rules and state transitions. |
| V. Simplicity & Provisional Scope | PASS | No refresh-token machinery, no JWT, no caching (keeps SC-3 exact), no quotas; expiry configurable for dev; single bootstrap admin. |
| Tech: Docker Compose | PASS | compose gains `auth-service` with `depends_on` health ordering; RAG `AUTH_SERVICE_URL` wired; both `/health` probes public. |

No violations; complexity tracking table remains unfilled.

## Project Structure

### Documentation (this feature)

```text
specs/002-api-user-auth/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (/speckit.plan command)
├── data-model.md        # Phase 1 output (/speckit.plan command)
├── quickstart.md        # Phase 1 output (/speckit.plan command)
├── contracts/           # Phase 1 output (/speckit.plan command)
│   ├── auth-api.md
│   └── rag-auth-guard.md
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)

```text
auth-service/                    # NEW independently deployable service
├── Dockerfile                   # mirrors work-scope-rag pattern
├── requirements.txt             # fastapi, uvicorn, sqlalchemy-async, psycopg, pydantic, argon2-cffi, pytest stack
├── .env.example                 # DATABASE_URL, AUTH_INITIAL_ADMIN_USERNAME/PASSWORD, AUTH_TOKEN_EXPIRY_SECONDS
├── app/
│   ├── __init__.py
│   ├── main.py                  # FastAPI app, router wiring, /health
│   ├── api/
│   │   ├── __init__.py
│   │   ├── auth.py              # POST /auth/login, /auth/logout, GET /auth/verify
│   │   └── users.py             # admin: POST/GET /auth/users, PATCH/DELETE /auth/users/{id}
│   ├── core/
│   │   ├── __init__.py
│   │   ├── config.py            # env settings (DB URL, bootstrap admin, token TTL)
│   │   ├── db.py                # async engine + session factory (auth DB)
│   │   ├── security.py          # Argon2id hash/verify, token generation + SHA-256
│   │   └── errors.py            # HTTPException helpers (mirror RAG service)
│   ├── models/
│   │   ├── __init__.py
│   │   ├── user.py              # users table
│   │   └── token.py             # tokens table
│   ├── schemas/
│   │   ├── __init__.py
│   │   ├── auth.py              # login request/response, verify response
│   │   └── user.py              # create/update/list responses
│   ├── services/
│   │   ├── __init__.py
│   │   ├── auth_service.py      # login (token issue + throttle), logout, verify
│   │   └── user_service.py      # admin user CRUD + enable/disable + bootstrap seed
│   └── migrations/
│       ├── __init__.py
│       └── bootstrap.py         # create_all + admin seed (mirror RAG bootstrap)
└── tests/
    ├── __init__.py
    ├── conftest.py              # auth_test DB fixtures, test admin seed
    ├── unit/                    # security (argon2, token hash), throttle math
    ├── integration/             # login→verify→revoke round-trips against Postgres
    └── contract/                # HTTP contract tests against /contracts/auth-api.md

work-scope-rag/                  # EXISTING service — additive hardening only
├── app/
│   ├── main.py                  # wire auth dependency into routers
│   ├── core/
│   │   ├── config.py            # + AUTH_SERVICE_URL setting
│   │   └── auth_guard.py        # NEW: FastAPI dependency → /auth/verify; fail-closed 503
│   └── api/
│       ├── documents.py         # add dependency over all protected routes
│       └── queries.py           # add dependency over POST /query
└── tests/
    ├── contract/                # extended: 401/503 cases with injected fake verifier
    └── integration/             # extended: real auth-service verification round-trip

db/init.sql                      # add: CREATE DATABASE auth OWNER rag; (+ auth_test)
docker-compose.yml               # add: auth-service (+ depends_on ordering for RAG)
```

**Structure Decision**: Single backend service per capability — a new `auth-service` (mirroring the `work-scope-rag` layout exactly: `api/` routers, `core/`, `models/`, `schemas/`, `services/`, `migrations/`, `tests/`) plus additive changes inside `work-scope-rag`. This keeps each capability independently deployable (Gate I) with no cross-service imports; both share only the Postgres server but no tables or code.

## Complexity Tracking

> Not required — Constitution Check passes with no violations.