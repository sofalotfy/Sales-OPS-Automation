# Research: Admin Dashboard

**Date**: 2026-09-10
**Purpose**: Resolve technical unknowns surfaced during planning of the admin dashboard feature (Laravel frontend service with login, controlling RAG documents).
**Source Feature**: [spec.md](./spec.md)

Context: small internal system, a handful of human operators, existing `auth-service` (opaque bearer tokens, `POST /auth/login`) and `work-scope-rag` (document CRUD at `/documents*`, all routes behind the auth guard). Constitution Gates I (HTTP-only between services, independent Dockerfiles), II (dashboards are UI, exempt from the FastAPI default, but the constitutional default names Streamlit), IV (Postgres is the source of truth), V (smallest version that satisfies the spec). Stakeholder explicitly requested a **Laravel** frontend service.

## 1. Framework and runtime for the new service (Laravel version, PHP)

- **Decision**: Laravel **13.x** on **PHP 8.4**, in a multi-stage Docker image (stage 1: Node + Vite compiles `tailwindcss`/`alpinejs` assets; stage 2: `composer install`; final runtime `php:8.4` running `php artisan serve --host=0.0.0.0 --port=8002`). Runtime is the [php official CLI image]() plus `ext-mbstring`, `ext-pdo`/`ext-pgsql` not required (no DB) but `openssl`, `tokenizer`, `ctype`, `session`, `filter`, `hash` are Laravel 13 requirements.
- **Rationale**: Laravel 13 (released March 2026) is the current major, requires PHP ≥8.3, supported through Q1 2028 (per Packagist / laravel.com release table, Sept 2026). PHP 8.4 is the conservative sweet spot in the supported window (8.3–8.5). `artisan serve` is the single-process built-in server — adequate for a handful of internal admins at v1 (a real nginx+php-fpm split is a port/Compose tweak later, explicitly out of scope). Multi-stage keeps the final image slim, mirroring the slim Python images used by `work-scope-rag`/`auth-service`.
- **Alternatives considered**: php:8.4-apache or php:8.4-fpm + nginx sidecar (rejected — two-process setup adds Compose surface with no benefit at this scale); prebuilt `laravelframework` official image template (overkill); PHP 8.5 (bleeding edge, no need).

## 2. Where the upstream auth token lives (authentication pattern for the dashboard)

- **Decision**: **Server-side session holds the upstream token.** The dashboard's `AuthController` receives the credentials on `POST /login`, calls `auth-service POST /auth/login`, and stores `{access_token, expires_at, username}` in a **file-backed Laravel session**. Every outbound call to `work-scope-rag` reads the token from the session and attaches `Authorization: Bearer <token>`. The token is never rendered to the browser, never in local/sessionStorage, never in a JS-readable cookie or a Livewire public property. On any upstream `401` (expired/revoked/disabled), the session is cleared and the user is redirected to `/login` (spec FR-10; SC-4 ≤ 5 min).
- **Rationale**: The spec's UX and security requirements (SC-1, FR-10, FR-001) are met with the simplest safe shape. Server-side sessions keep the opaque token out of XSS reach (it exists only in the web process and transitively in the mono image's session files), and a single `401` handler gives uniform, immediate session expiry — matching the auth-service's no-cache revocation guarantee. No CORS/origin configuration between dashboard and backends is needed because all calls are server-to-server within the Compose network (Gate I).
- **Alternatives considered**: Token in browser (localStorage/sessionStorage) + direct browser→RAG calls (rejected — exposes the token to every XSS on the page, requires CORS machinery on both services, and contradicts the one-boundary theme of the project); token in a signed browser cookie with direct calls (rejected — same CORS problem); Laravel Sanctum-managed local user store (rejected — would duplicate the `auth-service` user model, violating Gate IV/IV's single source of truth).

## 3. UI approach for a "good UI" admin dashboard (TALL vs Filament vs SPA)

- **Decision**: **TALL stack — Blade + Tailwind CSS + Livewire 4 + Alpine.js.** `DocumentsTable` (list + status badges + `wire:poll` for status refresh), `UploadDocument` (multipart add form), and `EditDocument` (metadata-only form) are Livewire 4 components; Alpine handles interactions that never leave the browser (open/close, delete confirmation). Layout is a dashboard chrome (top bar + content) built with Tailwind utility classes.
- **Rationale**: Livewire 4 (Jan 2026) is the current production guidance for exactly this workload — admin panels, settings pages, CRUD dashboards — because list/filter/status interactions round-trip server-side with small diffs, validation happens once (server-side, no duplicated rules), and no separate API/JS contract is maintained (research: "Livewire is now the right default for admin panels, settings pages, dashboards, multi-step forms"). It also aligns with the project's human-in-the-loop ethos: the status data and errors stay server-owned and are presented to a human, not massaged client-side. Tailwind gives a polished, consistent look with low effort, satisfying FR-011.
- **Alternatives considered**: **Filament** (the dominant Laravel admin-panel framework) — rejected because Filament's resource model is Eloquent-coupled (it drives CRUD off Laravel models/relations), while our documents live in an external HTTP service with no local Eloquent model; we would be fighting the framework to wrap `Http` calls in custom resources. **Vue/React SPA** (rejected — a separate frontend build + API contract + CORS layer is more moving parts than an internal tool needs, per research on SPA-fit). **Plain Blade with full page reloads** (rejected — a raw reload-based table is a meaningfully worse "good UI"; Livewire's cost is small).

## 4. The RAG API gap: editing document metadata without re-uploading

- **Decision**: Add a metadata-only **`PATCH /documents/{document_id}`** to the RAG service (body `{title?, source?}`, at least one field, no file), owned by `work-scope-rag` itself. The dashboard consumes it for FR-006.
- **Rationale**: The existing `PUT /documents/{id}` requires a file and atomically swaps content+chunks — the only way to change a title today is re-uploading the file, which is unacceptable UX for a "good UI" and would also re-run the embedding pipeline just to rename. A metadata-only PATCH is the minimal, spec-consistent change (spec FR-006 explicitly requires editing metadata; the spec's assumption that the dashboard works "on top of what the RAG service supports" is superseded by the hard FR-006 requirement). Adding it inside `work-scope-rag` keeps ownership of the document data model in one place (Gate IV), exactly paralleling how feature 002 added auth-guard behavior there additively.
- **Alternatives considered**: Re-upload-on-PUT from the UI (rejected — forces pointless re-embedding and file re-selection); a dashboard-local metadata store (rejected — violates Gate IV, creates a second source of truth); a generic write-through proxy in the dashboard (rejected — creates a competing API surface instead of extending the owning service's documented contract).

## 5. Sessions storage / new infrastructure

- **Decision**: **File-backed Laravel sessions** (`SESSION_DRIVER=file`), no database, no Redis, no queue for the dashboard.
- **Rationale**: The only session state is a transient upstream token for a handful of operators; Laravel's file driver is transactional and lock-safe for a single instance and needs zero infrastructure. Constitution Gate V: introducing a DB or Redis purely to store a session would violate the "smallest version" and "simplicity non-negotiable" principles, and Gate IV reserves Postgres for business state the dashboard doesn't own. Redis stays reserved for the async task queue per the constitution.
- **Alternatives considered**: Laravel with a Postgres session table (rejected — new DB, new table, no benefit); Redis-backed sessions (rejected — Redis is scoped to the task queue); cookie-encrypted sessions with token inside (rejected — moves the token toward client side and opens token-replay surface).

## 6. Testing the dashboard service

- **Decision**: PHPUnit feature tests using Laravel's `Http::fake()` for both upstream clients (login success/failure → session state; document list/add/edit/delete flows; `401` → forced re-login redirect), unit tests for URL/header construction, plus the RAG service's existing pytest suite gaining unit/contract/integration tests for `PATCH`. End-to-end scenarios are recorded in [quickstart.md](./quickstart.md) and exercised against the real Compose stack.
- **Rationale**: The dashboard has no Eloquent state, so framework-native feature tests with faked HTTP give deterministic coverage of every acceptance scenario without a browser. Contract tests pin the dashboard↔upstream HTTP boundaries (documented in `contracts/`), and the RAG `PATCH` change is covered by the service's existing test conventions rather than inventing a new harness. The quickstart provides the manual/curl end-to-end proof for spec success criteria.
- **Alternatives considered**: Browser-based E2E (Playwright/Dusk) (rejected — added harness for a few screens; quickstart scenarios plus faked-feature tests give the same confidence at lower cost, and Dusk can come later with real user-facing dashboards); testing only via curl (rejected — no automated regression protection).

## Decision Log Summary

| Decision | Chosen | Key alternative rejected |
|----------|--------|--------------------------|
| Framework/runtime | Laravel 13.x on PHP 8.4, multi-stage image, `artisan serve` | nginx+fpm split, PHP 8.5 |
| Auth/token pattern | Server-side file session holds upstream opaque token; 401 → forced re-login | Token in browser + CORS calls; Sanctum user store |
| UI stack | TALL (Blade + Tailwind + Livewire 4 + Alpine) | Filament, Vue/React SPA, plain reload Blade |
| Metadata editing | Additive `PATCH /documents/{id}` on RAG service | Re-upload-on-PUT UX, dashboard-local metadata store |
| Session storage | File-backed (no DB/Redis/queue) | Postgres session table, Redis sessions |
| Testing | PHPUnit + `Http::fake()` (dashboard); pytest contract/integration (RAG PATCH); quickstart E2E | Browser E2E suite, curl-only |