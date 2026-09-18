# Dashboard Web Contract

Base URL: `http://dashboard:8002` (Docker Compose DNS; host-mapped to a port for browser access, e.g. `http://localhost:8002`). Server-rendered web UI over the existing backend services — all document/user state lives upstream; the dashboard holds only a server-side session with the auth token ([data-model.md](../data-model.md)).

**Upstream dependencies** (the only services the dashboard talks to — constitution Gate I):

| Upstream | Contract | Used for |
|----------|----------|----------|
| `auth-service` | `POST /auth/login` (see [auth-api.md](../../002-api-user-auth/contracts/auth-api.md)) | Sign-in: exchange credentials for the opaque bearer token. |
| `auth-service` | `POST /auth/logout` (bearer) | Sign-out: revoke the held token. |
| `work-scope-rag` | `/documents*` (see [document-api.md](../../001-rag-document-service/contracts/document-api.md) and [document-metadata-api.md](./document-metadata-api.md)) | List / add / edit / delete documents with the session's bearer token. |

## Auth / session behavior

- **Unauthenticated access**: any request to a protected route without a session token → `302` redirect to `/login`. Management pages render nothing without an authenticated session (spec FR-001).
- **Sign-in**: `POST /login` with `username` + `password` → forwards to `auth-service POST /auth/login`. On `201`, stores `{access_token, token_type, expires_at, username}` in the session and redirects to `/documents`. On `401`/`429` (wrong credentials or throttling), re-renders the login page with a clear, non-revealing error (spec FR-002; auth-service already prevents enumeration).
- **Upstream `401` while working**: if `work-scope-rag` rejects the session's token (expired, revoked, user disabled — spec FR-010), the session is cleared and the user is redirected to `/login`. SC-4 requirement (disabled admin locked out in < 5 min) is inherited from the auth-service's no-cache introspection; the dashboard only needs to react to the `401`.
- **Sign-out**: `POST /logout` → clears local session and best-effort calls `auth-service POST /auth/logout` with the token; redirects to `/login`.
- **CSRF**: Laravel's built-in CSRF protection applies to all state-changing forms. Session cookie is `HttpOnly`; the token never appears in DOM/JS (research §2).

## Page routes

| Route | Auth | Purpose | Upstream call(s) |
|-------|------|---------|------------------|
| `GET /` | Public | Redirect to `/login` (anon) or `/documents` (signed-in). | — |
| `GET /login` | Public | Sign-in form. | — |
| `POST /login` | Public | Sign-in submission. | `POST auth-service /auth/login` |
| `POST /logout` | Signed-in | Sign out (CSRF form). | `POST auth-service /auth/logout` |
| `GET /health` | Public | Liveness probe (constitution Gate II / compose healthcheck). Returns `{"status":"ok"}`. | — |
| `GET /documents` | Signed-in | Documents list: table with title, source, file type, status badge, chunk count, created/updated, and per-row Edit + Delete actions. Empty state prompts to upload the first document (spec edge case). | `GET work-scope-rag /documents` |
| `GET /documents/create` | Signed-in | Add-document form (file + title + source metadata). | — |
| `POST /documents` | Signed-in | Create submission (multipart). | `POST work-scope-rag /documents` |
| `GET /documents/{id}/edit` | Signed-in | Metadata-only edit form prefilled from the list row. | (list data) |
| `POST /documents/{id}` | Signed-in | Edit submission (title/source). | `PATCH work-scope-rag /documents/{id}` |
| `POST /documents/{id}/delete` | Signed-in | Delete submission (confirmation step in UI). | `DELETE work-scope-rag /documents/{id}` |

## Status display (spec US-3 / FR-008)

Each row renders the RAG-provided `status` as a colored badge:

| Status | Badge style | Meaning |
|--------|-------------|---------|
| `processing` | amber | Ingest in progress (Livewire `wire:poll` refreshes the row). |
| `ready` | green | Queryable. |
| `failed` | red | Pipeline error — row shows the RAG error summary text for human review (Gate III). |
| unknown | gray | Defensive fallback; never crashes the table. |

## Error surfacing (spec FR-009, edge cases)

- RAG `409` (duplicate content) → inline "duplicate" message, form preserved.
- RAG `422`/`400` (validation) → field-level or form-level inline errors.
- RAG unreachable (`503`-style upstream failure) → documents page shows "backend unavailable" banner; mutations abort cleanly with no data change.
- Empty list → "No documents yet" empty state with a prominent add action.

## Response codes (dashboard's own HTTP surface)

| Code | Condition |
|------|-----------|
| 200 | Login page, documents page (signed-in), health. |
| 302 | Redirect to `/login` when unauthenticated / on upstream `401`; redirects after successful login/logout/create/edit/delete. |
| 422 | Laravel form validation failure (rendered inline; token untouched). |
| 503 | Only forced as an upstream-unavailable state rendered by the UI banner — the dashboard itself never fabricates data.