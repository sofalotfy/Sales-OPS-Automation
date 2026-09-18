# Data Model: Admin Dashboard

**Date**: 2026-09-10
**Source**: [spec.md](./spec.md) (Key Entities), [research.md](./research.md)

The dashboard introduces **no new persistent data**. Per constitution Gate IV the source of truth remains the shared PostgreSQL server, and per Gate I the dashboard is a HTTP-only consumer. The only state the dashboard owns is a **transient, file-backed session** (Laravel `SESSION_DRIVER=file`) that briefly holds a copy of the upstream auth token so the web process can authorize calls to the RAG service.

Business entities below are documented for reference; their storage is owned by `work-scope-rag` and `auth-service` respectively (see `specs/001-rag-document-service/data-model.md` and `specs/002-api-user-auth/data-model.md`).

## Entity: `document` (read/written ONLY via `work-scope-rag` API — not stored in the dashboard)

| Field | Type | Constraints / Notes |
|-------|------|---------------------|
| `document_id` | UUID (string form) | Returned by RAG API. URL path parameter for edit/delete. |
| `title` | TEXT | Editable by the dashboard via `PATCH /documents/{id}` (metadata). Used to render list rows. |
| `source` | TEXT (nullable) | Editable via `PATCH` / set at ingest. Filterable in the RAG list API. |
| `file_type` | TEXT | Computed at ingest; read-only in the UI. |
| `status` | TEXT | `processing` \| `ready` \| `failed` (derived by the RAG pipeline). Read-only; drives the status badge (FR-008, US-3). |
| `chunk_count` | INTEGER | Read-only; rendered in the list. |
| `created_at` / `updated_at` | TIMESTAMPTZ | Read-only; rendered as timestamps. |
| `error` | TEXT (nullable) | RAG surfaces error detail for failed documents; dashboard shows a human-readable summary (US-3 acceptance scenario 2). |

**Validation rules the dashboard enforces in its forms** (mirroring the RAG API contract):
- `title`: non-empty, ≤ 512 chars (RAG `String(512)`).
- `source`: optional, ≤ 255 chars (RAG `String(255)`), non-blank if provided.
- At least one of `title`/`source` present for a `PATCH` (empty-body edits are rejected `400`).

**State transitions (status) — driven entirely upstream, presented by the dashboard:**

```text
        ingest        pipeline done        pipeline error
   absent ──► processing ──────────► ready
                    │                    │
                    │   pipeline error    │  edit/delete allowed while ready
                    ▼                    ▼
                 failed              (deleted)
```

The dashboard never writes `status`; it displays it. Deletion (`DELETE /documents/{id}`) is the only destructive action the UI offers, always with a confirmation step (human-in-the-loop, Gate III).

## Entity: `token` (owned by `auth-service`; transiently held by the dashboard session)

| Field | Held in session | Notes |
|-------|-----------------|-------|
| `access_token` | YES | Opaque bearer string returned once by `auth-service POST /auth/login`. Attached by `RagApiClient` as `Authorization: Bearer`. Never rendered to the browser, never logged, never a Livewire public property. |
| `token_type` | YES | `bearer`. |
| `expires_at` | YES | Login time + 90 days (auth-service FR-5). Used to pre-emptively redirect to login when expired. |
| `username` | YES | Logged-in administrator's username, shown in the top bar. |

Validity lifecycle handled upstream: the auth-service enforces expiry/revocation/disablement on every introspection; the dashboard's `AuthenticateWithUpstream` middleware treats any upstream `401` as "session over" → clear session → redirect to `/login` (spec FR-010, SC-4).

## Entity: `dashboard_session` (transient, file-backed — the dashboard's only own state)

| Field | Notes |
|-------|-------|
| session id | Laravel file session id (cookie on the browser is session-only, HttpOnly). |
| token + meta | The upstream token fields above, stored server-side in `storage/framework/sessions`. |
| `_flash` | Laravel flash messages (validation errors, upstream errors surfaced for human review — Gate III). |

No business entity, no tables, no migrations. `SESSION_DRIVER=file`; a future multi-instance deployment would move to a shared driver, but v1 is a single container.

## Relationships

- `dashboard_session` 1 ── 1 `token` (copied from auth-service at login).
- `dashboard_session` 0..N ── 1 `document` (the session authorizes CRUD on any document via the RAG API).
- The dashboard has no relationships of its own; all others resolve through the two upstream APIs.

## Concurrency & failure notes

- One browser tab ⇒ one session ⇒ one bearer token; multiple tabs share the token via the same session cookie (no per-tab tokens — acceptable at this scale).
- If `auth-service` or `work-scope-rag` is unreachable, the dashboard's upstream calls fail visibly: the documents list renders a clear "backend unavailable" message rather than crashing (spec edge case), and login fails with an explanatory error. No silent success.
- Logout clears the local session and best-effort calls `auth-service /auth/logout` to revoke the token (auth-service contract, FR-006); if that call itself fails, the local session is still cleared so access ends immediately.