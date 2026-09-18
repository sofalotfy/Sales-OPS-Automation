# work-scope-rag

RAG document service for company-scope knowledge: ingests plain-text, Markdown,
and PDF documents and exposes an API to add, replace, delete, list, and
semantically query them. This is one of the independently deployable services
in the Sales Inbound Workflow (see `.specify/memory/constitution.md`).

## Requirements

- Docker + Docker Compose
- Internet access on first run to download the embedding model
  (`BAAI/bge-small-en-v1.5`)

## Run

```bash
docker compose up --build -d
curl -s http://localhost:8000/health   # -> {"status":"ok"}
```

Postgres (with `pgvector`) runs in the `db` container; tables are created
automatically on service startup.

## Configuration

Set via environment (see `.env.example`):

| Variable | Default | Description |
|----------|---------|-------------|
| `DATABASE_URL` | `postgresql+psycopg://rag:rag@db:5432/rag` | Async psycopg3 SQLAlchemy URL |
| `EMBEDDING_MODEL` | `BAAI/bge-small-en-v1.5` | Sentence-transformers model |
| `MAX_FILE_SIZE_MB` | `10` | Max document upload size |

## API overview

OpenAPI docs: `http://localhost:8000/docs`

- `POST /documents` — ingest (multipart file + metadata JSON). Synchronous; responds `201` with a `document_id` only after the document is queryable.
- `GET /documents` — list document metadata (pagination, `source`/`status` filters).
- `PUT /documents/{id}` — atomically replace a document (keeps the same ID, no queryable gap).
- `DELETE /documents/{id}` — delete a document and its chunks.
- `POST /query` — semantic search: returns ranked passages with similarity scores, optional `top_k` and metadata `filters`; unknown terms yield an explicit empty result set.
- `GET /health` — liveness probe.

## Tests

The test suite runs in the container and uses a separate `rag_test` database
(created by `db/init.sql`) so test teardown never touches live data.

```bash
docker compose up -d db                    # Postgres must be healthy
docker compose run --rm \
  -v "$PWD/work-scope-rag":/worksrc:z \
  -v rag-model-cache:/root/.cache \
  -e DATABASE_URL=postgresql+psycopg://rag:rag@db:5432/rag_test \
  -w /worksrc \
  work-scope-rag python -m pytest -q
```