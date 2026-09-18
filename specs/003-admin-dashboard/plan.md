# Implementation Plan: Admin Dashboard

**Branch**: `003-admin-dashboard` | **Date**: 2026-09-10 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/003-admin-dashboard/spec.md`

## Summary

Add a dashboard as a new, independently deployable service for the RAG document system: a **Laravel 13** (PHP 8.4) web app that authenticates administrators against the existing `auth-service` (login via `POST /auth/login`, issuing the project's opaque bearer token) and manages documents through the existing `work-scope-rag` API (`GET/POST/DELETE /documents`). The bearer token is held in a **server-side file-backed Laravel session** — the browser never sees it; every outbound RAG call attaches it as `Authorization: Bearer`. A per-document status UI (processing / ready / failed) gives operators the visibility the spec's success criteria demand. One small **additive** extension is made to the RAG service — `PATCH /documents/{document_id}` for metadata-only edits — because the current API only allows replace-with-file (`PUT`), which would force re-uploading a file just to rename a document (poor UX, contradicts FR-006). The dashboard stores **no business data** and reads **no database** (file-backed sessions only) — it consumes the two existing services exclusively over HTTP, which is where the "front end to the project" boundary lives.

Research decisions (see [research.md](./research.md)): Laravel 13 on PHP 8.4; server-side session holding the upstream token (BFF-style, token never in the browser); TALL-stack UI (Blade + Tailwind + Livewire 4 + Alpine) for a polished, mostly-server-driven admin panel; file-backed sessions (no DB for the dashboard); single-container image running `php artisan serve` at v1 scale; additive `PATCH /documents/{id}` on the RAG service for metadata edits.

## Technical Context

**Language/Version**: PHP 8.4 for the new `dashboard` service (Laravel 13.x, released Mar 2026, requires ≥8.3, supports 8.3–8.5). Additive RAG change stays Python 3.12 (matches existing `work-scope-rag`).

**Primary Dependencies**: laravel/framework `^13`, livewire/livewire `^4` (TALL stack), blade/Blade + tailwindcss + alpinejs (compiled via Vite), phpunit (framework default) for dashboard tests. RAG service gains **no** new packages (FastAPI/pydantic already present; `PATCH` uses the existing router/service/schema stack).

**Storage**: None for the dashboard — file-backed Laravel sessions only (`SESSION_DRIVER=file`), holding a transient copy of the upstream token + username + expiry. All business state remains in the shared Postgres server, owned by `work-scope-rag` (`documents`) and `auth-service` (`users`/`tokens`); the dashboard reaches none of it directly (Gate I/IV).

**Testing**: PHPUnit feature tests for the dashboard using Laravel's `Http::fake()` against stub auth/RAG responses (login, redirect-on-401, CRUD flows). RAG service: unit + contract tests for the new `PATCH` endpoint in the existing pytest suite; one HTTP integration test through the real `auth-service` token + dashboard-style calls. End-to-end scenarios in [quickstart.md](./quickstart.md).

**Target Platform**: Linux, Docker Compose (new `dashboard` image alongside `db`, `auth-service`, `work-scope-rag`).

**Project Type**: Web application (server-rendered frontend service consuming two backend services over HTTP).

**Performance Goals**: Document list page renders and shows data in <5 s on a typical connection (spec SC-1); login latency is dominated by the auth-service's Argon2id verify (~100–500 ms, per research); RAG list round trip <1 s on the compose network. Scale is tiny (a handful of internal operators).

**Constraints**: The dashboard MUST NOT touch a database or another service's internals — HTTP only (Gate I). The upstream token MUST stay server-side (never rendered to the browser, never logged). Any `401` from `work-scope-rag` clears the session and redirects to sign-in (token expired/revoked/disabled — spec FR-10, edge case). No new databases, no queue, no Redis.

**Scale/Scope**: A handful of internal administrators; ~1–3 screens (login, document list, add/edit forms); single tenant.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Constitution Principle | Status | Notes |
|------------------------|--------|-------|
| I. Independently Deployable Services | PASS | New `dashboard` image/service; communicates ONLY over HTTP to `auth-service` (`POST /auth/login`) and `work-scope-rag` (`/documents*`). Reads no database, no shared imports, no reaching into another service's tables. |
| II. API-First, FastAPI, `/health` | PASS (with justified deviation) | Dashboard is a UI, so it is exempt from the FastAPI backend default (the constitution's own carve-out — "the dashboard uses Streamlit because it's a UI, not an API"). The concrete stack **deviates from the constitutional default (Streamlit → Laravel) per the stakeholder's explicit request for a Laravel frontend service**. Justified in Complexity Tracking below. The dashboard still exposes `GET /health` for compose probes. |
| III. Human-in-the-Loop for Ambiguity | PASS | The dashboard is a management surface for human administrators; it adds no auto-resolution of ambiguous cases. Deletes are explicit human actions, status/error details are surfaced for human judgment (failed documents stay visible — FR-008). |
| IV. Data Model Is the Source of Truth | PASS | Document and user/token state stay exclusively in shared Postgres, owned by the RAG and auth services respectively. The dashboard holds only a transient session copy of the auth token and never becomes a second source of truth. |
| V. Simplicity & Provisional Scope | PASS | Smallest version serving the spec: login + list + add + metadata edit + delete + status badges. No role management, no audit log, no bulk ops, no user-facing dashboards (rejected in spec assumptions). Livewire keeps the good-UI requirement without building an SPA. |
| Tech: Docker Compose orchestration | PASS | `dashboard` added to compose; `depends_on: service_healthy` for `auth-service` and `work-scope-rag`; single `docker compose up` still brings up the whole stack. |
| Tech: Redis-backed task queue | PASS (not used) | File-backed sessions and a few HTTP calls need no queue; the dashboard introduces no background work in v1. Redis remains scoped to the async task queue. |
| Tech: PostgreSQL source of truth | PASS | No new database; dashboard relies on the existing Postgres-backed services. |

### Post-Design Re-check (Gate: re-evaluated after Phase 1)

Re-checked against the Phase 1 design artifacts (data-model.md, contracts/, quickstart.md):

| Principle | Status | Post-design verification |
|-----------|--------|--------------------------|
| I. Independently Deployable Services | PASS | `dashboard/` is self-contained (own Dockerfile, no DB). `dashboard-web.md` documents the only two outbound links (`auth-service`, `work-scope-rag`). The RAG service owns the new `PATCH /documents/{id}` endpoint itself. |
| II. API-First, FastAPI, `/health` | PASS (deviation justified) | `dashboard` exposes `GET /health` (used by the compose healthcheck) and a web UI; all backing services remain FastAPI. Stack deviation (Streamlit default → Laravel) stands justified in Complexity Tracking. |
| III. Human-in-the-Loop | PASS | Statuses and error strings are shown as-is for human review; failed documents are distinguishable (badge), never silently hidden or auto-deleted. |
| IV. Data Model Source of Truth | PASS | data-model.md makes explicit that the dashboard owns no business entity; `documents`/`users`/`tokens` remain owned by their services in shared Postgres. |
| V. Simplicity & Provisional Scope | PASS | Single-page Livewire flows, no SPA, no new infra (no queue/Redis/DB). The `PATCH` addition is the minimal change that makes FR-006 testable. |
| Tech: Docker Compose | PASS | compose gains `dashboard` (port 8002) with health ordering; env wiring `AUTH_API_URL`/`RAG_API_URL` documented in quickstart. |

No unresolved violations; the single justified deviation (dashboard stack) is recorded below.

## Project Structure

### Documentation (this feature)

```text
specs/003-admin-dashboard/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (/speckit.plan command)
├── data-model.md        # Phase 1 output (/speckit.plan command)
├── quickstart.md        # Phase 1 output (/speckit.plan command)
├── contracts/           # Phase 1 output (/speckit.plan command)
│   ├── dashboard-web.md
│   └── document-metadata-api.md
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)

```text
dashboard/                         # NEW independently deployable service
├── Dockerfile                     # multi-stage: Vite build (node) + composer install → php:8.4 runtime, `php artisan serve --host=0.0.0.0 --port=8002`
├── .env.example                   # APP_URL, SESSION_DRIVER=file, AUTH_API_URL=http://auth-service:8001, RAG_API_URL=http://work-scope-rag:8000
├── composer.json                  # laravel/framework ^13, livewire/livewire ^4, phpunit
├── package.json                   # tailwindcss, alpinejs + vite build scripts
├── artisan
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php        # GET /login, POST /login (→ auth-service), POST /logout
│   │   │   └── DashboardController.php   # GET / and /documents (list shell)
│   │   └── Middleware/
│   │       └── AuthenticateWithUpstream.php  # require active session token; on upstream 401 → clear session + redirect to /login
│   ├── Services/
│   │   ├── AuthApiClient.php             # thin httpx-style wrapper → auth-service /auth/login, /health
│   │   └── RagApiClient.php              # wrapper → work-scope-rag /documents*; attaches Bearer from session
│   ├── Livewire/
│   │   ├── DocumentsTable.php            # list + status badges + wire:poll refresh + delete confirm
│   │   ├── UploadDocument.php            # multipart upload form (add)
│   │   └── EditDocument.php              # metadata-only edit form (calls PATCH)
│   └── Models/                           # none in v1 — all document state lives upstream
├── routes/web.php                 # login/logout, dashboard index, document create/edit routes
├── resources/
│   ├── views/
│   │   ├── layouts/app.blade.php
│   │   ├── auth/login.blade.php
│   │   └── documents/
│   │       ├── index.blade.php           # DocumentsTable
│   │       ├── create.blade.php          # UploadDocument
│   │       └── edit.blade.php            # EditDocument
│   ├── css/app.css               # @tailwind directives
│   └── js/app.js                 # alpine + livewire entry (Vite-built)
├── storage/framework/sessions    # file-backed sessions (transient upstream token; never the browser)
└── tests/
    ├── Feature/
    │   ├── AuthFlowTest.php              # login success/failure, 401 → forced re-login, logout
    │   └── DocumentManagementTest.php    # list/add/edit/delete with Http::fake() (+ redirect on 401)
    └── Unit/                             # RagApiClient/AuthApiClient URL + header construction

work-scope-rag/                    # EXISTING service — additive change only
├── app/
│   ├── api/documents.py           # + PATCH /documents/{document_id} (metadata-only update)
│   ├── schemas/document.py        # + UpdateMetadataRequest / response
│   └── services/ingestion.py      # + update_metadata(document_id, title, source)
└── tests/
    ├── contract/                  # + PATCH contract tests (200/404/422)
    └── integration/               # + PATCH round-trip against real Postgres

docker-compose.yml                # + dashboard service (port 8002, env URLs, depends_on health)
```

**Structure Decision**: A dedicated `dashboard/` Laravel 13 web service (own Dockerfile) mirroring the project's one-capability-per-service layout, plus a deliberately minimal, additive change inside `work-scope-rag` (one new `PATCH` endpoint owned by that service). The dashboard never gains models, migrations, or a database — it is a pure HTTP/UI consumer, which is exactly what constitution Gates I and IV require. Two thin client classes (`AuthApiClient`, `RagApiClient`) isolate the two upstream APIs so the TALL UI code stays free of HTTP plumbing.

## Complexity Tracking

> Constitution Check has one justified violation to record.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Dashboard stack deviates from the constitutional default: **Streamlit → Laravel** | The stakeholder (user) explicitly requested a Laravel project as the dashboard front end; the constitution's "Streamlit" default is a provisional convenience, not a business requirement, and Gate II already carves out dashboards as UI-not-API | Sticking with the constitutional Streamlit default (unchanged) would ignore the explicit stakeholder request; using a plain Blade-only dashboard (no Livewire) would still satisfy the request but deliver a noticeably poorer "good UI" for list/forms at essentially zero extra complexity |