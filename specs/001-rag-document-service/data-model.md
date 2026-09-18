# Data Model: RAG Document Service

**Date**: 2026-09-05
**Source**: [spec.md](./spec.md) (Key Entities) and [research.md](./research.md)

All tables live in the shared PostgreSQL database (constitution Gate IV). The vector store is a table in the same database — no separate vector DB.

## Entity: `documents`

A single ingested company-scope document (plain text, Markdown, or PDF).

| Field | Type | Constraints / Notes |
|-------|------|---------------------|
| `id` | UUID | Primary key; server-generated. Public document identifier returned to callers. |
| `title` | TEXT | Computed from provided metadata or file name. |
| `source` | TEXT | Origin hint supplied by caller (e.g. "sales-playbook"). |
| `file_type` | TEXT | `text` \| `markdown` \| `pdf`. |
| `content_hash` | TEXT | SHA-256 of normalized source content. Used for duplicate detection (FR-8/edge case) and replace guards. |
| `metadata` | JSONB | Arbitrary caller-supplied metadata; indexable for query filters (FR-14). Defaults to `{}`. |
| `status` | TEXT | `processing` → `ready` \| `failed`. See state transitions below. |
| `embedding_model` | TEXT | Model id used for chunk embeddings; recorded so a model change forces re-index. |
| `char_count` | INTEGER | Total characters of extracted text. |
| `chunk_count` | INTEGER | Number of chunks in the current index (denormalized counter). |
| `error` | TEXT | Set when `status=failed` with a human-readable reason. Null otherwise. |
| `created_at` | TIMESTAMPTZ | Ingestion timestamp. |
| `updated_at` | TIMESTAMPTZ | Last status/content change timestamp. |

## Entity: `chunks`

A retrievable segment of a document, with its stored embedding vector.

| Field | Type | Constraints / Notes |
|-------|------|---------------------|
| `id` | UUID | Primary key; server-generated. |
| `document_id` | UUID | FK → `documents.id`, ON DELETE CASCADE. Indexed (btree). |
| `chunk_index` | INTEGER | Zero-based position within the document. |
| `text` | TEXT | Chunk text (what is returned to the query caller). |
| `embedding` | vector(384) | Stored embedding for similarity search (cosine distance). |
| `token_count` | INTEGER | Approximate token count of the chunk (for tuning visibility). |

**Uniqueness**: `UNIQUE (document_id, chunk_index)` — a document's chunks form an ordered sequence.

## Relationships

- `documents` 1 ── N `chunks` (cascade delete).
- A `query` operates over `chunks`, joining to `documents` for metadata filters and to exclude documents not in `ready` status.

## State Transitions (`documents.status`)

```text
          ingest                       embed+store fail
   ┌───> processing ──────────────────────────────────────> failed
   │        │  embed + insert chunks OK
   │        ▼
   └───  ready
```

- `processing`: entered on ingest/replace start (synchronous — the request is in flight).
- `ready`: entered once all chunks are committed in a single transaction (FR-11: only then does the request respond).
- `failed`: entered when extraction, chunking, or embedding fails; the error is returned to the caller and recorded in `documents.error` (human-in-the-loop supports debugging without silent dropping).

## Validation Rules

- `documents.title` and content: non-empty after extraction (FR-8).
- File size: ≤ 10 MB (configurable); plain text max length bound to same cap.
- PDFs: must contain extractable text (image-only PDFs rejected as invalid — FR-8).
- `chunks.text`: non-empty; chunk index sequence contiguous from 0.
- Query (`verbatim` from FR-14): `top_k` positive integer, capped at 20 (matches prototype `MAX_TOP_K`); metadata filters must be object key/value pairs.

## Atomic Replace Semantics (FR-13)

In a single transaction:
1. Delete all rows from `chunks` where `document_id = :id`.
2. Insert the new chunk rows for the document.
3. Update `documents` (`content_hash`, `char_count`, `chunk_count`, `embedding_model`, `status=ready`, `updated_at`).

Embedding generation happens *before* the transaction opens (inference must not hold a DB transaction). Because delete+insert commit atomically, no reader ever observes a document with no chunks or a partial mix — the document is either fully old or fully new (no queryable gap).

## Concurrency

- Two simultaneous replaces of the same document serialize on the row lock acquired at update; chunk inserts are ordered by `(document_id, chunk_index)` to avoid deadlock on concurrent upserts.
- Dedup: a document whose `content_hash` matches an existing `ready` document with the same `source`+`title` is rejected with a 409 until deconflicted (configurable; see edge cases in spec).