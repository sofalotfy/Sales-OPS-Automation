# Research: RAG Document Service

**Date**: 2026-09-05
**Purpose**: Resolve technical unknowns surfaced during planning for the RAG document service.

Context: small corpus (tens to a few hundred documents), internal-only consumers, FastAPI service backed by shared PostgreSQL/pgvector (constitution-mandated), synchronous ingestion (clarified FR-11).

## 1. Vector store integration (pgvector + FastAPI)

- **Decision**: SQLAlchemy 2.0 async engine with psycopg3 (`postgresql+psycopg://`), `pgvector` Python package, `Vector(384)` columns. No ANN index — exact nearest-neighbor scan. Single transaction for replace; embeddings computed outside the transaction.
- **Rationale**: psycopg3 is the official libpq wrapper with graceful behavior behind poolers and clean pgvector type registration (register_vector_async); asyncpg's server-side prepared-statement cache causes `InvalidCachedStatementError` in transaction mode. Exact scan is sub-ms and gives perfect recall below ~50–100k rows — an index would only add tuning (hnsw.ef_search) and reduced-recall pitfalls. Embedding generation is slow (inference), so the DB transaction must not stay open during it. The shared Postgres is already the constitution's source-of-truth pattern.
- **Alternatives considered**: asyncpg (faster but pooler + codec pitfalls); synchronous psycopg2 (fallback for migrations only); HNSW/ivfflat index now (rejected — recall decay on incremental inserts, unnecessary at this scale); ChromaDB (rejected — contradicts constitution Gate IV; the existing `rag/` prototype is retired).

## 2. Embedding model

- **Decision**: Local `sentence-transformers` model `BAAI/bge-small-en-v1.5` (384-dim), used for both semantic chunking and retrieval, isolated behind a thin wrapper.
- **Rationale**: Anthropic provides no embedding API (confirmed — generation-only; Voyage AI is their recommended partner but is an external dependency). A local 384-dim model costs nothing, runs offline on CPU inside Docker (~130MB model), has zero per-query latency variance, and matches larger models on small corpora. Single model keeps one vector space and one dependency for both chunking and retrieval.
- **Alternatives considered**: Voyage AI / OpenAI / Cohere embeddings (hosted dependency, higher dim, marginal recall gain at this scale); `all-MiniLM-L6-v2` (the prototype's model — rejected for 256-token input cap and roughly 5–6 fewer MTEB points); bge-large/e5 variants (unjustified 8x latency at this scale).

## 3. PDF extraction and ingestion constraints

- **Decision**: PyMuPDF (`fitz`) for PDF text extraction. Hard per-file cap of 10 MB (configurable), streamed from the uploaded file object. Reject image-only/scanned PDFs with a clear 422 message (detect near-empty extracted text). Validate MIME + magic bytes; reject encrypted/password-protected PDFs.
- **Rationale**: PyMuPDF is a C binding — 10–50x faster than pure-Python extractors with the best text accuracy and reading order; no system deps, easy wheel install. Graceful failure aligns with human-in-the-loop (no silent empty-vector indexing). AGPL license is acceptable for an internal, non-distributed service (legal should be consulted before any external distribution).
- **Alternatives considered**: pdfplumber (great tables, but slow and fails on multi-column reading order); pypdf (lightweight but garbles spaced/multi-column text); Tesseract OCR (rejected — heavy dependency not needed for text-based company PDFs).

## 4. Chunking strategy

- **Decision**: Keep the semantic chunker approach (sentence-transformers similarity threshold + max-sentences cap) as the primary strategy, with configurable knobs: target ~500 tokens, overlap 10–20%, similarity threshold default 0.55, max 8 sentences per chunk. Documents under ~500 tokens are embedded whole.
- **Rationale**: It is already implemented in the prototype and at this scale the ingest-time cost is negligible; benchmarks conflict on whether semantic or recursive chunking wins, so the implementation keeps both measurable and records results in the plan/tasks. Chunks are pre-embedded once at ingestion; the query path embeds only the query.
- **Alternatives considered**: RecursiveCharacterTextSplitter (candidate A/B to validate against on own corpus); fixed-size windowing (fallback if tuning shows poorer recall).

## 5. Embedding/query-time policy

- **Decision**: Compute embeddings once per chunk at ingestion time and store them in the chunks table; at query time embed only the query (~sub-ms) then scan for similarity. Record `embedding_model` on each document so a model change triggers a clean re-index.
- **Rationale**: Standard, near-universal RAG pattern; recomputing chunk embeddings per query is prohibitively slow.

## Open items deferred to implementation/tasks

- Exact tuning of chunk size/threshold against the real corpus (golden-set evaluation optional, quickstart defines smoke-test targets).
- Whether Memory needs a dedicated migration tool (Alembic) or `create_all` bootstrap for v1 — flagged in tasks.
- Confirming AGPL acceptability with legal only if external distribution is ever planned.

## Decision Log Summary

| Decision | Chosen | Departs from prototype? |
|----------|--------|-------------------------|
| Vector store | Postgres + pgvector (exact scan) | Yes — replaces ChromaDB |
| Embedding model | bge-small-en-v1.5 (384d, local) | Yes — replaces all-MiniLM-L6-v2 |
| DB driver | SQLAlchemy 2 async + psycopg3 | New |
| PDF extraction | PyMuPDF | New |
| Chunking | Semantic chunker (tuned) | No — ported |
| Ingestion/replace | Sync; embed outside txn, replace in one txn | New |