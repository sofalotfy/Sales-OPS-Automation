# Document API Contract

Base URL: `http://<host>:8000` · All routes `Content-Type: application/json` (except ingest, which is `multipart/form-data`).

**Errors** (all endpoints): error responses use JSON `{"detail": "<human-readable message>"}` with the appropriate status code. Invalid inputs return `422`; missing resources return `404`; duplicate content returns `409`.

## `GET /health`

Liveness probe (constitution Gate II). No auth.

**Response `200`**

```json
{ "status": "ok" }
```

## `POST /documents` — Ingest a document (synchronous, FR-1/FR-11/FR-12)

Multipart form with one file and one JSON field. The request does not complete until the document is chunked, embedded, and committed (queryable).

- `file`: the document file (accepted types: `text/plain`, `text/markdown`, `application/pdf`; max 10 MB).
- `metadata`: JSON string, e.g. `{"title":"Sales Playbook 2026","source":"playbooks"}`. `title` defaulted from the file name when absent.

**Response `201`**

```json
{
  "document_id": "6f0c8f2a-...-uuid",
  "status": "ready",
  "chunk_count": 24,
  "title": "Sales Playbook 2026",
  "source": "playbooks"
}
```

**Error cases**

| Code | Condition |
|------|-----------|
| 400 | Unsupported file type, empty content after extraction, or missing file. |
| 409 | Content duplicate of an existing ready document (same `content_hash` + `source` + `title`). |
| 422 | File too large (> 10 MB); image-only/scanned PDF (empty extracted text); empty/blank metadata object; malformed multipart. |
| 500 | Embedded store failure, chunking failure (surfaced with `detail`; documents row marked failed). |

## `GET /documents` — List documents (FR-7)

Query params: `source` (optional filter), `status` (optional), `limit` (default 50, max 200), `offset` (default 0).

Returns metadata only — never chunk text.

**Response `200`**

```json
{
  "items": [
    { "document_id": "...", "title": "Sales Playbook 2026", "source": "playbooks",
      "file_type": "pdf", "status": "ready", "chunk_count": 24,
      "created_at": "2026-09-05T10:00:00Z", "updated_at": "2026-09-05T10:00:00Z" }
  ],
  "total": 42,
  "limit": 50,
  "offset": 0
}
```

## `PUT /documents/{document_id}` — Replace a document atomically (FR-13)

Same request shape as ingest. Existing chunks are atomically swapped for the new ones in a single transaction; the document ID is preserved; there is no window where the document is unqueryable.

**Response `200`** — same shape as ingest response.

**Errors**: `404` unknown document; `409` duplicate of another document; `422` invalid file/large.

## `DELETE /documents/{document_id}` — Remove a document (FR-6)

Cascades to all chunks atomically.

**Response `200`**

```json
{ "deleted": true, "document_id": "..." }
```

**Errors**: `404` unknown document.