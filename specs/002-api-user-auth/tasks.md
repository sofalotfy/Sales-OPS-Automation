---

description: "Task list for feature implementation"

---

# Tasks: API User Authentication

**Input**: Design documents from `/specs/002-api-user-auth/`

**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md, contracts/, quickstart.md

**Tests**: The spec does not request a formal test suite, so dedicated test tasks are omitted by rule. Each story carries an **Independent Test** (manual/quickstart scenario) used to validate the increment. Follow the existing `work-scope-rag/tests` conventions if the implementer adds coverage.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

- New service: `auth-service/` (mirrors `work-scope-rag/` layout exactly)
- RAG hardening: `work-scope-rag/`
- Paths below are concrete per plan.md.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization, Docker Compose wiring, and database provisioning on the shared Postgres server

- [X] T00[1-7] Create `auth-service/` project skeleton (directories `app/`, `app/api/`, `app/core/`, `app/models/`, `app/schemas/`, `app/services/`, `app/migrations/`, `tests/unit/`, `tests/integration/`, `tests/contract/` each with `__init__.py` where Python packages)
- [X] T00[1-7] [P] Create `auth-service/Dockerfile` mirroring the `work-scope-rag/Dockerfile` pattern (python:3.12-slim, pip install, uvicorn entrypoint on port 8001)
- [X] T00[1-7] [P] Create `auth-service/requirements.txt` (fastapi, uvicorn[standard], sqlalchemy[asyncio], psycopg[binary], pydantic, pydantic-settings, argon2-cffi, pytest, pytest-asyncio, httpx)
- [X] T00[1-7] [P] Create `auth-service/.env.example` (DATABASE_URL, AUTH_INITIAL_ADMIN_USERNAME, AUTH_INITIAL_ADMIN_PASSWORD, AUTH_TOKEN_EXPIRY_SECONDS)
- [X] T00[1-7] [P] Create `auth-service/pytest.ini` mirroring `work-scope-rag/pytest.ini`
- [X] T00[1-7] [P] Add `CREATE DATABASE auth OWNER rag;` and `CREATE DATABASE auth_test OWNER rag;` to `db/init.sql`
- [X] T00[1-7] [P] Add `auth-service` to `docker-compose.yml` (build ./auth-service, port 8001:8001, env_file, `depends_on: db: service_healthy`, restart unless-stopped) and add `AUTH_SERVICE_URL=http://auth-service:8001` plus `depends_on: auth-service: service_healthy` to the `work-scope-rag` service

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core auth-service plumbing that MUST be complete before ANY user story can be implemented

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [X] T008 Create `auth-service/app/core/config.py` with `Settings` (database_url default `postgresql+psycopg://rag:rag@db:5432/auth`, auth_initial_admin_username, auth_initial_admin_password, auth_token_expiry_seconds default `7776000` = 90 days, env_file=".env")
- [X] T009 [P] Create `auth-service/app/core/db.py` (async engine + `async_session_factory` bound to the auth database, mirroring `work-scope-rag/app/core/db.py`)
- [X] T010 [P] Create `auth-service/app/core/errors.py` (HTTPException helpers mirroring `work-scope-rag/app/core/errors.py`: unauthorized 401, forbidden 403, not_found 404, conflict 409, validation 422, too_many_requests 429, internal 500)
- [X] T011 [P] Create `auth-service/app/core/security.py` (Argon2id hash/verify via `argon2-cffi` using OWASP baseline params; `generate_token()` via `secrets.token_urlsafe(48)`; `token_hash()` as SHA-256 hex)
- [X] T012 [P] Create `auth-service/app/models/base.py` + `auth-service/app/models/user.py` (`users` table exactly per `data-model.md` §Entity: users — id, username UNIQUE, password_hash, role, is_enabled, failed_attempts, locked_until, created_at, updated_at)
- [X] T013 [P] Create `auth-service/app/models/token.py` (`tokens` table exactly per `data-model.md` §Entity: tokens — id, user_id FK→users CASCADE, token_hash UNIQUE, expires_at, revoked_at, created_at, last_used_at)
- [X] T014 Create `auth-service/app/core/dependencies.py` (async `get_db` session dependency; `get_current_user` Bearer-token dependency that looks up `token_hash` → `tokens` row → `users` row and rejects expired/revoked/disabled; `require_admin` dependency that also enforces `role == "admin"`)
- [X] T015 Create `auth-service/app/migrations/bootstrap.py` (`create_all` for auth tables on startup + seed first admin from env when `users` is empty; fail loudly if env admin creds missing and no users exist) and `auth-service/app/main.py` (FastAPI app, lifespan=bootstrap, `GET /health` returning `{"status":"ok"}`, router mounts referenced in US1/US2)
- [X] T016 [P] Create `auth-service/tests/conftest.py` with auth-DB test fixtures (session against `auth_test` database, test admin, per-test truncate)

**Checkpoint**: Foundation ready — `auth-service` boots, health probe OK, tables created, first admin seeded. User story implementation can now begin.

---

## Phase 3: User Story 1 - User Account Management (Priority: P1) 🎯 MVP

**Goal**: An administrator can create user accounts with unique credentials, list them, and enable/disable (and delete) them. No self-service signup.

**Independent Test**: Log in with the seeded admin (T015). `POST /auth/users` creates a `user`; duplicate username returns `409`; `GET /auth/users` lists it; `PATCH /auth/users/{id}` with `{"is_enabled":false}` blocks that user's next login; a `user`-role token calling `POST /auth/users` returns `403`. Contract: `contracts/auth-api.md` §Admin user management.

### Implementation for User Story 1

- [X] T017 [P] [US1] Create `auth-service/app/schemas/user.py` (UserCreate, UserUpdate {is_enabled}, UserOut, UserListOut — never expose password_hash)
- [X] T018 [US1] Implement `auth-service/app/services/user_service.py` (create with Argon2id hash from `core/security.py`; password ≥12 chars; username validated `^[A-Za-z0-9_.-]+$`, max 100; list w/ enabled filter + limit/offset; enable/disable; delete with last-enabled-admin guard → 409)
- [X] T019 [US1] Implement `auth-service/app/api/users.py` (routes `POST /auth/users` 201, `GET /auth/users` 200, `PATCH /auth/users/{user_id}` 200, `DELETE /auth/users/{user_id}` 200; `get_current_user` + `require_admin` dependencies; 409 duplicate, 422 invalid, 404 unknown, 403 non-admin)
- [X] T020 [US1] Wire `users` router into `auth-service/app/main.py` (import from `app.api.users`)

**Checkpoint**: User account management is fully functional and testable independently (spec US1: acceptance scenarios 1–3).

---

## Phase 4: User Story 2 - Login and Token-Based Access (Priority: P1) 🎯 MVP

**Goal**: A user logs in with credentials and receives an opaque 90-day bearer token. Every protected RAG route carries it in the `Authorization: Bearer` header and is enforced per-request via `auth-service GET /auth/verify`; `GET /health` stays public; auth-service unavailability fails closed with `503`.

**Independent Test**: Login as the seeded admin → `201` with `access_token`. `GET /documents` with no header → `401`; with a garbage token → `401`; with the valid token → `200`. Contracts: `contracts/auth-api.md` §POST /auth/login, §GET /auth/verify, and `contracts/rag-auth-guard.md`.

### Implementation for User Story 2

- [X] T021 [P] [US2] Create `auth-service/app/schemas/auth.py` (LoginRequest, LoginResponse {access_token, token_type, expires_at}, VerifyResponse {user_id, username, role})
- [X] T022 [US2] Implement `auth-service/app/services/auth_service.py` (login: generic-error credential check, progressive backoff per `data-model.md` (failed_attempts increment + `locked_until = now + min(2^n s, 15min)`, reset on success), issue token with expiry from settings, store SHA-256 hash; verify: resolve `token_hash` → token → user, reject expired/revoked/disabled, update last_used_at)
- [X] T023 [US2] Implement `auth-service/app/api/auth.py` (routes `POST /auth/login` public → 201, `GET /auth/verify` authenticated → 200; generic `401` messages that never reveal username-vs-password; `429` when throttled)
- [X] T024 [US2] Wire `auth` router into `auth-service/app/main.py` (merge with US1 wiring T020)
- [X] T025 [P] [US2] Add `auth_service_url` setting to `work-scope-rag/app/core/config.py` and `AUTH_SERVICE_URL=http://auth-service:8001` to `work-scope-rag/.env.example`
- [X] T026 [US2] Create `work-scope-rag/app/core/auth_guard.py` (FastAPI dependency: read `Authorization: Bearer` header; forward to `auth-service GET /auth/verify` via httpx.AsyncClient; `401` passthrough as `401 Not authenticated.`; auth-service unreachable/error → fail closed `503 Authorization service unavailable.`; no result caching)
- [X] T027 [P] [US2] Wire `auth_guard` dependency into all four protected routes of `work-scope-rag/app/api/documents.py` (POST/GET/PUT/DELETE `/documents`)
- [X] T028 [P] [US2] Wire `auth_guard` dependency into `POST /query` in `work-scope-rag/app/api/queries.py`
- [X] T029 [US2] Confirm in `work-scope-rag/app/main.py` that `GET /health` and `GET /` (→docs redirect) remain public and unguarded

**Checkpoint**: The public RAG API is restricted — scenario 2 of `quickstart.md` passes (no token → 401, valid token → 200).

---

## Phase 5: User Story 3 - Token Lifecycle and Disablement (Priority: P2)

**Goal**: Tokens and accounts remain governable after login: logout revokes a token immediately; expired tokens and tokens of disabled users are refused; an administrator's disablement takes effect on the very next request.

**Independent Test**: Login → `POST /auth/logout` → the same token returns `401` on next RAG call. With a dev-short `AUTH_TOKEN_EXPIRY_SECONDS` (e.g. 1), a token used after expiry → `401`; fresh login works. Admin `PATCH /auth/users/{id} {is_enabled:false}` (US1) → that user's next RAG request → `401`. Contract: `contracts/auth-api.md` §POST /auth/logout; data-model §State Transitions.

### Implementation for User Story 3

- [X] T03[0-2] [US3] Add `logout()` to `auth-service/app/services/auth_service.py` (set `revoked_at` on the presented token row; concurrent sessions unaffected)
- [X] T03[0-2] [US3] Add `POST /auth/logout` to `auth-service/app/api/auth.py` (authenticated; `200 {"revoked": true}`; `401` for missing/invalid tokens)
- [X] T03[0-2] [US3] Audit `auth-service/app/services/auth_service.py` verify path: confirm expiry (`expires_at <= now()`), `revoked_at`, and `is_enabled` are all enforced so disablement/revocation block the very next request (spec SC-3) and login refuses disabled users (FR-4)

**Checkpoint**: All user stories functional — quickstart scenarios 5–7 (disable, logout, expiry) pass.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: End-to-end validation, configuration hygiene, and security hardening across both services

- [X] T033 [P] Verify `.env.example` files for `auth-service/` and `work-scope-rag/` are complete and match `core/config.py` settings (DATABASE_URL, AUTH_INITIAL_ADMIN_*, AUTH_TOKEN_EXPIRY_SECONDS, AUTH_SERVICE_URL)
- [X] T034 [P] Confirm `.gitignore` covers `.env` files and no secrets (admin password, tokens) are committed anywhere
- [X] T035 Review error messaging for leakage (generic login/`401` messages only; no endpoints reveal whether a username exists) and confirm `GET /health` is public on both services (spec FR-8)
- [X] T036 Run every scenario in `specs/002-api-user-auth/quickstart.md` against the running compose stack (admin provisioning, login+token, protected/unprotected RAG calls, disable, logout, expiry, `403` for non-admin) and confirm all Success Criteria in `spec.md`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately
- **Foundational (Phase 2)**: Depends on Setup; BLOCKS all user stories
- **User Stories (Phase 3+)**:
  - US1 (P1) and US2 (P1) both depend only on Foundational — can proceed in parallel
  - The two `main.py` router-wiring tasks (T020, T024) must merge sequentially
  - US3 (P2) depends on US2's auth service files (T022/T023 — add to the same `auth_service.py`/`api/auth.py`) and is demonstrated via US1's disable endpoint
- **Polish (Final Phase)**: Depends on all stories being complete

### User Story Dependencies

- **User Story 1 (P1)**: After Foundational — no dependency on other stories
- **User Story 2 (P1)**: After Foundational — no dependency on US1 (login works for the seeded admin alone)
- **User Story 3 (P2)**: After Foundational + US2 (shares `auth_service.py`/`api/auth.py`); integrates with US1's enable/disable for its independent test

### Within Each User Story

- Schemas before services before endpoints before wiring (T017 → T018 → T019 → T020; T021 → T022 → T023 → T024)
- Core implementation before integration (US2: auth-service side before RAG guard)

### Parallel Opportunities

- Setup (Phase 1): T002–T007 all parallel after T001
- Foundational (Phase 2): T009–T013 parallel; T014 (dependencies) after T011–T013; T015 after models+security; T016 parallel to T009–T015
- US1 and US2 can be implemented in parallel by two agents (distinct files: `users.*` vs `auth.*` + `work-scope-rag/...`) — only T020/T024 (both `app/main.py`) must not run concurrently
- Within US2: T027 and T028 parallel after T026

---

## Parallel Example: User Story 2

```bash
# Auth-service side (before RAG guard):
Task: "Create auth-service/app/schemas/auth.py (T021)"
Task: "Add auth_service_url to work-scope-rag/app/core/config.py (T025)"

# RAG guard wiring after T026:
Task: "Wire auth_guard into work-scope-rag/app/api/documents.py (T027)"
Task: "Wire auth_guard into work-scope-rag/app/api/queries.py (T028)"
```

## Parallel Example: User Story 1

```bash
# Sequential chain (single file dependencies):
Task: "Create schemas/user.py (T017)"        # parallel branch off
Task: "Create schemas/auth.py (T021)"        # US2, parallel to T017-T020
```

---

## Implementation Strategy

### MVP First (User Stories 1 & 2 — both P1)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL — blocks all stories; ends with seeded admin + bootable service)
3. Complete Phase 3 (US1): User Account Management → test independently
4. Complete Phase 4 (US2): Login + RAG guard → test independently
5. **STOP and VALIDATE**: quickstart scenarios 1–4 + 8–9 pass; deploy/Demo (this is the MVP — it actually restricts the public API)

> Note: US1 and US2 are both P1; the absolute-minimum demo (seed admin → login → guard) is just Phase 1 + 2 + US2. US1 is still required to reach MVP by the spec, which lists account management as P1.

### Incremental Delivery

1. Foundation ready (bootable auth-service + admin seed)
2. Add US1 → independent test → kept
3. Add US2 → independent test → MVP complete (public API restricted)
4. Add US3 → independent test → full feature
5. Polish → quickstart validation across the whole spec's Success Criteria

### Parallel Team Strategy

1. Team completes Setup + Foundational together
2. Once Foundational is done:
   - Developer A: User Story 1 (users.*, main.py wiring T020)
   - Developer B: User Story 2 (auth.*, then RAG guard T025–T029)
   - Merge T020/T024 main.py wiring at integration
3. Developer B (or one dev) then completes US3 in the same auth files

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- Each user story is independently completable and testable (Independent Test per phase)
- No formal test tasks per the rules (spec does not request a test suite); the project convention (`work-scope-rag/tests/contract`, `tests/integration`) may be extended by the implementer as time permits, and quickstart.md is the canonical validation harness
- Commit after each task or logical group
- Stop at any checkpoint to validate the story independently