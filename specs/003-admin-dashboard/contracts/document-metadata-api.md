# Document Metadata API Contract (RAG service extension)

This is an **additive amendment to** [document-api.md](../../001-rag-document-service/contracts/document-api.md) from this feature (spec **FR-006**: edit an existing document's metadata). Base URL and auth are unchanged: `http://work-scope-rag:8000`, all `/documents` routes protected (see [rag-auth-guard.md](../../002-api-user-auth/contracts/rag-auth-guard.md)), errors as `{"detail": "<message>"}`.

**Why this exists**: `PUT /documents/{document_id}` requires a file and atomically re-embeds content. Renaming a title (or correcting a source label) should not force a pointless re-embed, so metadata-only edits get their own lightweight endpoint. It changes **no existing routes or response shapes**.

## `PATCH /documents/{document_id}` — update document metadata (FR-006)

Request body (JSON, `Content-Type: application/json`):

```json
{ "title": "Sales Playbook 2026 (rev 3)", "source": "playbooks-2026" }
```

- `title`: optional string, 1–512 chars after trim.
- `source`: optional string, 1–255 chars after trim, or `null` to clear.
- At least one of `title` / `source` MUST be present (empty body is an error).
- No file, no status, no chunk/embedding changes: metadata rows update only; `chunk_count`, `file_type`, `status`, `error` are untouched.

**Response `200`** — updated summary (same shape as `GET /documents` item, unchanged contract):

```json
{
  "document_id": "6f0c8f2a-...-uuid",
  "title": "Sales Playbook 2026 (rev 3)",
  "source": "playbooks-2026",
  "file_type": "pdf",
  "status": "ready",
  "chunk_count": 24,
  "created_at": "2026-09-05T10:00:00Z",
  "updated_at": "2026-09-10T09:30:00Z"
}
```

**Error cases**

| Code | Condition |
|------|-----------|
| 400 | Empty body or both `title` and `source` absent. Body: `{"detail": "Nothing to update."}` |
| 404 | Unknown `document_id` (or non-UUID id — same message as other document routes). |
| 422 | `title`/`source` not strings, blank after trim, too long, or body not a JSON object. |
| 401 | Missing/invalid/expired/revoked token, or disabled user (inherited from the auth guard). |
| 503 | Auth service unreachable (inherited fail-closed behavior). |

**Guarantees**

- Never touches chunks, embeddings, or derived fields — a metadata edit cannot change retrieval results.
- Replaces the prior recommended workaround (re-upload via `PUT` solely to rename) without altering `PUT` semantics.
- `updated_at` advances so the dashboard's list stays accurate.