# Quickstart: RAG Document Service

**Purpose**: Validate the feature end-to-end after implementation. Two-pass validation: (1) smoke test ingestion, (2) verification scenarios from the spec.

**Contracts**: [document-api.md](./contracts/document-api.md) · [query-api.md](./contracts/query-api.md) · **Data model**: [data-model.md](./data-model.md)

## Prerequisites

- Docker + Docker Compose.
- `.env` at `work-scope-rag/.env` with `DATABASE_URL=postgresql+psycopg://rag:rag@db:5432/rag` (matches the compose `db` service). `EMBEDDING_MODEL=BAAI/bge-small-en-v1.5` and `MAX_FILE_SIZE_MB=10` defaults applied if unset.
- Embedding model downloads on first run: allow network access for `huggingface.co` on the first container start.

## Setup

```bash
docker compose up --build -d        # brings up db + work-scope-rag
curl -s http://localhost:8000/health # expect {"status":"ok"}
```

A sample callable smoke document: `docs/smoke.md` (Markdown) and `docs/smoke.pdf` (text-layer PDF) created during implementation.

## Validation Scenarios (map to spec Acceptance Scenarios)

### 1. Synchronous ingestion works end-to-end (FR-1, FR-11)

```bash
curl -s -F "file=@docs/smoke.md" \
     -F 'metadata={"title":"Smoke","source":"test"}' \
     http://localhost:8000/documents
```

**Expected**: `201` with `document_id`, `status: "ready"`, `chunk_count > 0`, and — immediately (no polling) — the document is retrievable via the query endpoint below.

### 2. Query returns ranked results with scores + filters (FR-4, FR-5, FR-14)

```bash
curl -s -X POST http://localhost:8000/query -H 'Content-Type: application/json' \
     -d '{"query":"what does the company sell?","top_k":5,"filters":{"source":"test"}}'
```

**Expected**: `200`, `success: true`, `result_count >= 1`, results sorted descending by `similarity_score`, all from `source: "test"`, all with `document_id` matching the ingested doc.

### 3. Unknown-term query yields explicit empty result set (no-match edge case)

Same as #2 but `query:"zzz nonexistent qqq"`.

**Expected**: `200` with `result_count: 0` and `results: []` — not an error.

### 4. Atomic replace keeps the ID and leaves no gap (FR-13)

```bash
curl -s -X PUT -F "file=@docs/smoke-v2.md" -F 'metadata={"title":"Smoke v2","source":"test"}' \
     http://localhost:8000/documents/<id>
```

**Expected**: `200`, same `document_id`, `chunk_count` reflects v2 content; immediately follow with query #2 and confirm results reflect v2 only.

### 5. Delete then query (FR-6)

```bash
curl -s -X DELETE http://localhost:8000/documents/<id>
curl -s -X POST http://localhost:8000/query -H 'Content-Type: application/json' \
     -d '{"query":"what does the company sell?"}'
```

**Expected**: `200 {"deleted":true}`; then `result_count: 0` (no stale chunks). A second DELETE of the same ID returns `404`.

### 6. Validation rejected cleanly (FR-8, FR-9)

```bash
curl -s -F "file=@docs/empty.txt" -F 'metadata={"title":"empty"}' http://localhost:8000/documents
curl -s -X POST http://localhost:8000/query -H 'Content-Type: application/json' -d '{"query":""}'
```

**Expected**: `422` with a clear `detail` message for both.

## Success Target (spec Success Criteria, smoke baseline)

- Ingestion of a standard-length document becomes queryable in < 10 seconds.
- The full set of validation scenarios above passes against the running stack; query result quality spot-check ≥ 80% of prepared known-answer questions (a tiny golden set of 5–10 Q/A pairs prepared during implementation).

## Notes

- All of the above use the same external interface the triage service will consume; no UI.
- Integration (concurrent load: 100 ingest / 500 query) is exercised by `tests/integration` as a separate pytest suite, not by hand here.