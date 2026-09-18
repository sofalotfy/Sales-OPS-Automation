# Quickstart: Admin Dashboard

**Purpose**: Validate the feature end-to-end after implementation: login through the dashboard UI, sign-out/redirects, document management (list / add / metadata edit / delete), status badges, and the forced re-login path when the upstream token is revoked.

**Contracts**: [dashboard-web.md](./contracts/dashboard-web.md) · [document-metadata-api.md](./contracts/document-metadata-api.md) · **Data model**: [data-model.md](./data-model.md) · **Upstreams**: [auth-api.md](../../002-api-user-auth/contracts/auth-api.md) · [document-api.md](../../001-rag-document-service/contracts/document-api.md)

## Prerequisites

- Docker + Docker Compose (stack gains a `dashboard` service; `db`, `auth-service`, `work-scope-rag` unchanged).
- `dashboard/.env`: `AUTH_API_URL=http://auth-service:8001`, `RAG_API_URL=http://work-scope-rag:8000`, `SESSION_DRIVER=file`, `APP_URL=http://localhost:8002`.
- `auth-service` must have at least one admin seeded (its env-bootstrap: `AUTH_INITIAL_ADMIN_USERNAME`/`AUTH_INITIAL_ADMIN_PASSWORD`).
- Compose wiring: `dashboard` `depends_on` `auth-service` and `work-scope-rag` with `condition: service_healthy`, port `8002:8002`.

## Setup

```bash
docker compose up --build -d
curl -s http://localhost:8002/health     # dashboard: expect {"status":"ok"}
curl -s http://localhost:8001/health     # auth-service: {"status":"ok"}
curl -s http://localhost:8000/health     # work-scope-rag: {"status":"ok"}
```

Open `http://localhost:8002` in a browser — an unauthenticated visit must land on the sign-in page.

## Validation Scenarios (map to spec Acceptance Scenarios)

### 1. Unauthenticated access is blocked at the login screen (US-1, FR-001)

**Do**: Visit `http://localhost:8002` and try `http://localhost:8002/documents` directly without signing in.

**Expected**: Both land on `/login`. No document data or management controls render.

### 2. Administrator signs in (US-1, FR-002)

**Do**: Sign in with the seeded admin credentials.

**Expected**: Redirect to `/documents`; top bar shows the signed-in username; the (possibly empty) document table renders.

### 3. Wrong credentials are refused with a clear error (US-1, FR-002)

**Do**: Sign out (scenario 10), then sign in with a wrong password (repeat 5+ times to also exercise throttling).

**Expected**: Each failure re-renders the login page with a generic error; throttled attempts show a rate-limit message. No navigation to `/documents` occurs on any failure. Repeating a few times must not leak whether the username exists.

### 4. Documents list with empty state (US-2, FR-004)

**Do**: On a fresh stack, open `/documents`.

**Expected**: "No documents yet" empty state with a visible "Upload document" action.

### 5. Add a document (US-2, FR-005, SC-5)

**Do**: Create a plain-text fixture (e.g. `docs/smoke.md`-style content), then in the UI: *Add document* → choose the file → give a title and source → submit.

**Expected**: Redirect to the list; the new row appears with status **processing** resolving to **ready** within the poll interval; title/source/file type/chunk count visible. Completing the whole flow from "find entry point" to "row present" in under 3 minutes satisfies SC-5.

### 6. Status visibility and failure marking (US-3, FR-008)

**Do**: (a) Ingest the fixture and watch the badge flip processing → ready. (b) Upload a deliberately non-text content (e.g. a binary/image file the RAG service rejects) to force a pipeline failure.

**Expected**: (a) amber → green badge transition visible without manual page reload (`wire:poll`). (b) A red **failed** badge with the error summary shown for human review.

### 7. Edit metadata without re-uploading (US-2, FR-006)

**Do**: On a ready document, open *Edit*, change the title (and optionally the source), submit.

**Expected**: Redirect to the list with the new title — **no file re-select, status stays `ready`**. Verifiable at the API level too:

```bash
TOKEN=$(curl -s -X POST http://localhost:8001/auth/login -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"<seed-password>"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')
# fetch a document_id, then:
curl -s -X PATCH http://localhost:8000/documents/<document_id> \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"title":"Renamed without re-upload"}'
```

**Expected**: `200` summary with the new title; `chunk_count`/`status` unchanged. Empty body → `400 {"detail":"Nothing to update."}`.

### 8. Delete a document (US-2, FR-007, Gate III)

**Do**: Choose *Delete* on a document; a confirmation step is shown; confirm.

**Expected**: Row disappears; the document no longer appears in RAG results. Cancel must abort with no change. Deleting an already-deleting/processing row fails cleanly with the upstream message surfaced (spec edge case).

### 9. Disabled / revoked user is locked out on the next request (FR-010, SC-4)

**Do**: While signed in as `admin`, disable `admin` via the auth-service API:

```bash
curl -s -X PATCH http://localhost:8001/auth/users/<admin_id> \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' -d '{"is_enabled":false}'
```

then perform any document action (e.g. open `/documents`).

**Expected**: The next interaction is rejected upstream (`401`) and the dashboard redirects to `/login` immediately — well under the 5-minute SC-4 target. Re-enable the account afterward to continue.

### 10. Sign-out (US-1, FR-003)

**Do**: Click *Sign out*.

**Expected**: Redirect to `/login`; the token is revoked upstream (auth-service `POST /auth/logout`) and the local session cleared. `/documents` no longer renders management UI.

## Success Target (spec Success Criteria)

- Sign-in → documents list in under 5 s on a typical connection (SC-1); the dominant cost is the auth-service login round trip.
- 100% of add / edit (PATCH) / delete actions performed through the UI are observed in the RAG service (re-`GET /documents` or curl afterwards) (SC-2).
- Every invalid submission exercised above (wrong password, empty PATCH body, bad source) is rejected with a clear error and no unintended change (SC-3).
- Scenario 9 proves a disabled admin is locked out on the immediately following request — faster than the ≤ 5 min target (SC-4).
- Scenario 5 demonstrates the full add-a-document flow in under 3 minutes unaided (SC-5).
- `GET /health` returns until every service throughout (SC-5 complement from the auth feature).

## Notes

- Scenarios 1–10 exercise the UI plus the two documented upstream contracts; no separate raw-API client is needed.
- No new database/queue exists for this feature — `docker compose ps` shows exactly: `db`, `auth-service`, `work-scope-rag`, `dashboard`.
- The RAG `PATCH` behavior is also covered by pytest contract/integration tests in `work-scope-rag/tests`; the dashboard flows by PHPUnit feature tests with faked HTTP (see plan.md).