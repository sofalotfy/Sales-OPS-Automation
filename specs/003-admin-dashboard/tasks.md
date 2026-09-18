---

description: "Task list for Admin Dashboard feature implementation"
---

# Tasks: Admin Dashboard

**Input**: Design documents from `/specs/003-admin-dashboard/`

**Prerequisites**: [plan.md](./plan.md) (required), [spec.md](./spec.md) (user stories), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: Included — the design (plan.md research §6, quickstart.md) explicitly specifies PHPUnit feature tests with `Http::fake()` for the dashboard and pytest contract/integration tests for the additive RAG `PATCH` change.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

- New service: `dashboard/` (Laravel 13 project). Paths like `dashboard/app/...`, `dashboard/resources/...`, `dashboard/tests/...`.
- Additive change to existing service: `work-scope-rag/app/...`, `work-scope-rag/tests/...`.
- Orchestration: `docker-compose.yml`, `dashboard/.env`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Scaffold the new `dashboard` service, its container, and Compose wiring so every later task runs in one stack.

- [x] T001 Scaffold a Laravel 13 project on PHP 8.4 in `dashboard/` (`composer create-project laravel/laravel` pinned to `^13`, single `composer.json`, `artisan`, default `tests/`), remove boilerplate welcome view
- [x] T002 [P] Add multi-stage `dashboard/Dockerfile`: stage 1 Node + Vite builds `resources/css/app.css` + `resources/js/app.js`; stage 2 `composer install --no-dev`; final `php:8.4` runtime with `mbstring`,`openssl`,`tokenizer`,`ctype`,`session`,`filter`,`hash` extensions, `CMD ["php","artisan","serve","--host=0.0.0.0","--port","8002"]`
- [x] T003 Add `dashboard` service to `docker-compose.yml`: build `./dashboard`, `ports: "8002:8002"`, `env_file: ./dashboard/.env`, `depends_on` `auth-service` and `work-scope-rag` with `condition: service_healthy`, and a `curl`-style `GET /health` healthcheck so the rest of the stack keeps a single `docker compose up`
- [x] T004 [P] Create `dashboard/.env.example` (and copy to `dashboard/.env` for dev) with `APP_URL=http://localhost:8002`, `APP_ENV=local`, `SESSION_DRIVER=file`, `AUTH_API_URL=http://auth-service:8001`, `RAG_API_URL=http://work-scope-rag:8000`
- [x] T005 [P] Install `livewire/livewire:^4` (register `Livewire\LivewireServiceProvider` in `dashboard/bootstrap/providers.php`) and add `tailwindcss`, `alpinejs`, `@tailwindcss/vite` to `dashboard/package.json` with Vite entry points (`dashboard/vite.config.js`, `dashboard/resources/css/app.css` with `@tailwind` directives, `dashboard/resources/js/app.js` importing Alpine + Livewire)
- [x] T006 Configure upstream URLs in `dashboard/config/services.php` (`auth_api_url`, `rag_api_url`, read from env) and confirm `config/session.php` drives `SESSION_DRIVER` from env (file driver default)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The two upstream HTTP clients, the session-gating middleware, and the shared UI shell that EVERY user story needs.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T007 [P] Implement `dashboard/app/Services/AuthApiClient.php`: thin HTTP wrapper over `auth-service` — `login(username, password)` → `POST /auth/login`, `logout(token)` → `POST /auth/logout` with `Authorization: Bearer`, plus `health()`; decode errors without throwing on 4xx (return status + body for the controller to surface)
- [x] T008 [P] Implement `dashboard/app/Services/RagApiClient.php`: thin HTTP wrapper over `work-scope-rag` — `listDocuments(token, source?, status?, limit, offset)` → `GET /documents`, `createDocument(token, file, title, source)` → `POST /documents` (multipart), `updateMetadata(token, id, title?, source?)` → `PATCH /documents/{id}`, `deleteDocument(token, id)` → `DELETE /documents/{id}`; attaches `Authorization: Bearer` and returns status + decoded body so callers can handle 401/409/422/503 distinctly
- [x] T009 Implement session token store `dashboard/app/Support/UpstreamSession.php`: `put(accessToken, username, expiresAt)`, `token()`, `username()`, `expiresAt()`, `clear()` mapped onto the Laravel file session; a `tokenExpired()` helper so the middleware can bounce expired sessions without an upstream call
- [x] T010 Implement `dashboard/app/Http/Middleware/AuthenticateWithUpstream.php`: if no session token (or expired) → redirect `/login`; register it in `dashboard/bootstrap/app.php` on the web group so it wraps all management routes
- [x] T011 [P] Create the dashboard shell `dashboard/resources/views/layouts/app.blade.php` (top bar with app name, signed-in `username`, Sign out button) plus `dashboard/resources/css/app.css` (Tailwind directives) and `dashboard/resources/js/app.js` (Alpine + Livewire, Vite-compiled)
- [x] T012 Add public `GET /health` returning `{"status":"ok"}` in `dashboard/app/Http/Controllers/DashboardController.php` + `dashboard/routes/web.php` (no auth) for the Compose healthcheck
- [x] T013 [P] Create `dashboard/tests/Feature/TestCase.php` with shared `Http::fake()` fixtures (auth login success/failure, RAG list/add/edit/delete, upstream 401) so story test classes stay small; register in `dashboard/phpunit.xml`
- [x] T014 Create a reusable upstream-error banner component `dashboard/resources/views/components/upstream-error.blade.php` (renders the surfaced message for 409/422/503/upstream-unavailable with no crash)

**Checkpoint**: Foundation ready — user story implementation can now begin in parallel.

---

## Phase 3: User Story 1 - Administrator Logs In (Priority: P1) 🎯 MVP

**Goal**: An unauthenticated visitor is redirected to a polished sign-in page; an administrator signs in via the auth-service (token held server-side in the session), sees the dashboard, and can sign out; wrong credentials and revoked tokens never grant access.

**Independent Test**: Without a session, every management route redirects to `/login`; signing in with valid seeded admin credentials reaches the dashboard; wrong credentials show a generic error and never navigate; sign-out returns to `/login` and `/documents` is blocked again. (Automated: `dashboard/tests/Feature/AuthFlowTest.php` with `Http::fake()`; manual: quickstart scenarios 1–3, 10.)

### Tests for User Story 1 ⚠️

> **NOTE: Write these tests FIRST, ensure they FAIL before implementation**

- [x] T015 [P] [US1] Write `dashboard/tests/Feature/AuthFlowTest.php`: unauth redirect to `/login`; login success stores token + redirects to `/documents`; login failure re-renders page with generic error; 401-on-protected-route clears session and redirects; logout clears session and revokes upstream token; health stays public

### Implementation for User Story 1

- [x] T016 [US1] Implement `dashboard/app/Http/Controllers/AuthController.php`: `showLogin()` (GET `/login`), `login()` (POST `/login` → `AuthApiClient::login`, store session on success, redirect `/documents`, generic non-revealing error on 401/429, clear error if auth-service unreachable), `logout()` (POST `/logout` → best-effort upstream logout then clear session → redirect `/login`)
- [x] T017 [US1] Register routes in `dashboard/routes/web.php`: GET/POST `/login`, POST `/logout`, GET `/` redirecting to `/login` (anon) or `/documents` (signed-in); protect all management routes with `AuthenticateWithUpstream`
- [x] T018 [US1] Build `dashboard/resources/views/auth/login.blade.php` (Tailwind-styled centered card, username + password, inline error display, CSRF field; no raw token data anywhere in the view)

**Checkpoint**: User Story 1 fully functional and testable independently (MVP for login).

---

## Phase 4: User Story 2 - Manage RAG Documents (Priority: P1)

**Goal**: An authenticated administrator lists documents, uploads a new one, edits its title/source metadata, and deletes documents — all applied through the RAG service's API immediately.

**Independent Test**: Sign in and open `/documents` → existing documents render; upload a `.md`/`.txt`/`.pdf` → new row appears; edit title via the edit form (no file re-upload, status unchanged) → list reflects it; delete with confirmation → row disappears. Duplicate/invalid submissions surface clear errors and change nothing. (Automated: `dashboard/tests/Feature/DocumentManagementTest.php` with `Http::fake()` + RAG pytest `PATCH` tests; manual: quickstart scenarios 4–8.)

### Tests for User Story 2 ⚠️

> **NOTE: Write these tests FIRST, ensure they FAIL before implementation**

- [x] T019 [P] [US2] Write RAG contract tests for the additive `PATCH /documents/{id}` in `work-scope-rag/tests/contract/test_document_metadata.py` (200 update, 400 empty body, 404 unknown id, 422 bad fields, 401 no token) and one integration round-trip in `work-scope-rag/tests/integration/test_document_metadata.py` against real Postgres (metadata changes, `chunk_count`/`status` untouched, `updated_at` advances)
- [x] T020 [P] [US2] Write `dashboard/tests/Feature/DocumentManagementTest.php` with faked RAG responses: list renders rows; add submission posts multipart to `/documents` and redirects; edit posts `PATCH`-shaped payload and redirects; delete posts and redirects; 409 duplicate shows banner without data change

### Implementation for User Story 2

- [x] T021 [P] [US2] Add `PATCH /documents/{document_id}` to `work-scope-rag/app/api/documents.py` (metadata-only, body `{title?, source?}`, at least one field, mirrors contract in `contracts/document-metadata-api.md`), with `work-scope-rag/app/schemas/document.py` `UpdateMetadataRequest` and an `update_metadata(document_id, title, source)` method on `work-scope-rag/app/services/ingestion.py` (400 when nothing to update, 404 unknown id, 422 invalid values; never touches chunks/embeddings/status)
- [x] T022 [US2] Implement `dashboard/app/Http/Controllers/DashboardController.php` routes in `dashboard/routes/web.php`: GET `/documents` (index), GET `/documents/create`, POST `/documents` (multipart upload), GET `/documents/{id}/edit`, POST `/documents/{id}` (metadata edit → RagApiClient `updateMetadata`), POST `/documents/{id}/delete`; all redirect back to the list on success
- [x] T023 [US2] Implement `dashboard/app/Livewire/DocumentsTable.php`: loads `RagApiClient::listDocuments` with the session token, exposes per-row delete with an Alpine confirmation, and re-renders rows after mutations
- [x] T024 [P] [US2] Implement `dashboard/app/Livewire/UploadDocument.php`: file + title + source form, client-friendly validation (title ≤512, source ≤255 non-blank), calls `RagApiClient::createDocument`, surfaces 409 duplicate inline
- [x] T025 [P] [US2] Implement `dashboard/app/Livewire/EditDocument.php`: prefill from list row, title/source-only form, calls `RagApiClient::updateMetadata`, surfaces validation errors inline
- [x] T026 [US2] Build Blade views in `dashboard/resources/views/documents/`: `index.blade.php` (shell hosting DocumentsTable + empty state with add link), `create.blade.php` (UploadDocument), `edit.blade.php` (EditDocument) — Tailwind-styled, consistent with the app layout
- [x] T027 [US2] Handle error surfacing per `contracts/dashboard-web.md`: 409 inline duplicate, 422 field-level, upstream-unavailable banner via `upstream-error` component, and the RAG `401` path forces re-login through `AuthenticateWithUpstream` (no data change)

**Checkpoint**: User Stories 1 AND 2 both work independently (this is the full P1 scope).

---

## Phase 5: User Story 3 - Document Status Visibility (Priority: P2)

**Goal**: Each document row shows a colored status badge (processing / ready / failed), refreshes automatically until ready, and failed documents expose their error summary for human review.

**Independent Test**: Ingest a fixture and watch the row badge move amber (processing) → green (ready) without a manual reload; uploading a non-text file the RAG service rejects results in a red failed badge with its error text. (Automated: `dashboard/tests/Feature/StatusVisibilityTest.php` with mixed-status fakes; manual: quickstart scenario 6.)

### Tests for User Story 3 ⚠️

> **NOTE: Write these tests FIRST, ensure they FAIL before implementation**

- [x] T028 [P] [US3] Write `dashboard/tests/Feature/StatusVisibilityTest.php`: a faked `GET /documents` carrying `processing`/`ready`/`failed` rows renders the matching badges; a `failed` row includes its error summary; a status change between poll responses is reflected in badges

### Implementation for User Story 3

- [x] T029 [P] [US3] Add a `StatusBadge` Blade component `dashboard/resources/views/components/status-badge.blade.php` mapping RAG `status` → style (processing=amber, ready=green, failed=red, unknown=gray) per `contracts/dashboard-web.md` US-3 table
- [x] T030 [US3] Add `wire:poll.5s` refresh to `dashboard/app/Livewire/DocumentsTable.php` so processing rows update to ready/failed without manual reload (single poll component per page)
- [x] T031 [US3] Render the RAG error summary beside a failed document row in `dashboard/resources/views/documents/index.blade.php` (via DocumentsTable) for human review per constitution Gate III

**Checkpoint**: All user stories independently functional.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Security, hygiene, and end-to-end validation affecting all stories.

- [x] T032 [P] Security pass: assert the upstream token is never rendered to the browser, never in Livewire public props, never logged (`dashboard/app/Support/UpstreamSession.php`, controllers, `php artisan log:check`-style review); confirm CSRF on every mutating form and `HttpOnly` session cookie
- [x] T033 [P] Runtime optimization in `dashboard/Dockerfile` entrypoint: `php artisan config:cache && php artisan route:cache && php artisan view:cache` before `artisan serve` (first-request latency, per research §1)
- [x] T034 [P] Verify Compose healthcheck flows end to end: `docker compose up --build -d`, then `curl http://localhost:8002/health` returns `{"status":"ok"}` alongside `8001`/`8000` health
- [x] T035 [P] Run [quickstart.md](./quickstart.md) end-to-end (scenarios 1–10) and confirm every spec success criterion (SC-1..SC-5); fix any gap found
- [x] T036 Final review: run `dashboard/tests/Feature` (PHPUnit) and `work-scope-rag/tests` (pytest) — all green; confirm no `TODO`/stub markers are left without an owner; update any drift between `contracts/`, quickstart and code

**Checkpoint**: Feature complete and validated against the full spec.

---

## Phase 7: Dashboard Home, Navigation & Document View/Hosted Files (Follow-up)

**Purpose**: Dashboard home page with Documents as a sub-page, reliable back
navigation, and the ability to view a document's content and download the
original upload.

- [x] T037 RAG: store originals on ingest/replace — additive `original_data` (BYTEA) + `original_filename` columns on `documents`; expose `original_available` in the document summary; existing rows keep NULL (download reported unavailable) (`work-scope-rag/app/models/document.py`, `app/services/ingestion.py`)
- [x] T038 RAG: apply new columns idempotently to existing databases in `app/migrations/bootstrap.py` (`ALTER TABLE ... ADD COLUMN IF NOT EXISTS`), keeping `create_all` for fresh DBs
- [x] T039 RAG: additive `GET /documents/{document_id}/content` (reconstructed extracted text for preview) and `GET /documents/{document_id}/download` (stored original with correct media type + `Content-Disposition`); both behind `require_valid_token`, unknown/invalid id → 404
- [x] T040 RAG: contract + integration tests for content/download (exact original bytes round-trip, 404/401 cases, pre-retention doc behavior); full pytest suite green
- [x] T041 Dashboard: `RagApiClient::getDocumentContent` / `getDocumentFile`; routes `GET /`→ home dashboard (`dashboard.home`), `GET /documents/{id}/view`, `GET /documents/{id}/download`; `DashboardController::home/show/download`
- [x] T042 Dashboard: Documents is a sub-page — top-bar nav (Dashboard/Documents), stat cards (ready/processing/failed/total) + recent documents on the home page; post-login and signed-in-visitor redirects now target the dashboard home
- [x] T043 Dashboard: consistent `← Back to documents` link on upload/edit/view pages and a "Go to documents" link in the upload success notice (inline edit-form back link removed)
- [x] T044 Dashboard: view page (metadata + scrollable content preview, Download disabled with note when `original_available` is false) and streaming download pass-through; DocumentsTable rows gain View/Download actions
- [x] T045 Tests: `DashboardHomeTest`, `DocumentViewTest`, UpstreamStubs content/file/totals fakes, redirect updates in `AuthFlowTest`, row-actions assertions in `DocumentManagementTest`; PHPUnit green
- [x] T046 E2E: rebuild dashboard image (vite build made hermetic — removed remote-font plugin) and restart RAG so bootstrap applies new columns; verify login → home, view content, download round-trips exact original bytes, and unauthenticated view/download redirect to sign-in

**Checkpoint**: Dashboard is the main page, documents + view/download are
sub-pages, and navigation is always recoverable.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately
- **Foundational (Phase 2)**: Depends on Setup completion — BLOCKS all user stories
- **User Stories (Phase 3+)**: All depend on Foundational completion
  - US1 and US2 proceed in priority order (both P1), then US3 (P2)
- **Polish (Final Phase)**: Depends on all other phases being complete

### User Story Dependencies

- **User Story 1 (P1)**: Can start after Foundational (Phase 2) — no dependencies on other stories. MVP for login.
- **User Story 2 (P1)**: After Foundational — T021 (RAG PATCH) is independent of US1 and parallelizable with it; the dashboard CRUD UI depends only on RagApiClient (T008) and session middleware (T010).
- **User Story 3 (P2)**: After Foundational — reuses DocumentsTable from US2, so effectively follows US2, but its badge/poll/error components are independently testable in isolation.

### Within Each User Story

- Tests MUST be written and FAIL before implementation (T-tasks before their implementation tasks)
- Middleware/clients before controllers; controllers before Livewire components; Livewire before Blade views
- Story complete before moving to the next priority

### Parallel Opportunities

- All Setup tasks marked [P] can run in parallel (Dockerfile, env, dependencies, config)
- All Foundational tasks marked [P] can run in parallel (AuthApiClient, RagApiClient, layout, test fixtures)
- Test tasks within a story marked [P] can run in parallel
- US2's RAG PATCH work (T019, T021) is a fully separate service/fileset from the dashboard UI tasks — parallel with T022–T027 and with US1
- T024/T025 (UploadDocument / EditDocument) are separate component files — parallel

---

## Parallel Example: User Story 2

```bash
# Launch the additive RAG change and its tests together (separate service):
Task: "Write RAG contract/integration tests for PATCH in work-scope-rag/tests/..."
Task: "Add PATCH /documents/{id} to work-scope-rag/app/api/documents.py"

# Launch dashboard UI components together:
Task: "Implement DocumentsTable Livewire in dashboard/app/Livewire/DocumentsTable.php"
Task: "Implement UploadDocument Livewire in dashboard/app/Livewire/UploadDocument.php"
Task: "Implement EditDocument Livewire in dashboard/app/Livewire/EditDocument.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 + User Story 2 — both P1)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL — blocks all stories)
3. Complete Phase 3: User Story 1 (login) → STOP and VALIDATE via T015
4. Complete Phase 4: User Story 2 (document management) → STOP and VALIDATE via T019/T020 → this is the deliverable demo (admin can log in and manage documents)
5. Deploy/demo if ready; User Story 3 (status visibility) is the follow-on P2

### Incremental Delivery

1. Complete Setup + Foundational → Foundation ready (health checks green on all 4 services)
2. Add User Story 1 → test independently → sign-in works
3. Add User Story 2 → test independently → log in + manage documents (MVP)
4. Add User Story 3 → test independently → status badges (complete P1+P2 feature set)
5. Polish → security/optimization pass, quickstart validation

### Parallel Team Strategy

With multiple developers:

1. Team completes Setup + Foundational together
2. Once Foundational is done:
   - Developer A: US1 login (T015–T018)
   - Developer B: US2 RAG PATCH additive change (T019, T021) — different service
   - Developer C: US2 dashboard CRUD UI (T022–T027)
3. Dev B+C merge into US2; US3 follows as a small unit

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps the task to its user story for traceability
- Each user story is independently completable and testable
- Verify tests fail before implementing (write the T-tasks first)
- Commit after each task or logical group
- Stop at each checkpoint to validate the story independently
- The dashboard owns no database; confirm every task leaves `docker compose ps` at exactly `db`, `auth-service`, `work-scope-rag`, `dashboard` (no new services)
- Avoid: vague tasks, same-file conflicts, cross-story dependencies that break independence