# work-scope-rag

The document knowledge service for the Sales Ops project. It ingests
company-scope material — plain text, Markdown, and PDF — chunks it, embeds each
chunk, and serves semantic search over the result. This is what the dashboard
uploads to, and what a retrieval-augmented triage step would read from.

FastAPI + async SQLAlchemy on Postgres with `pgvector`. Served at
`http://localhost:8000`.

## How it works

1. **Ingest.** A file is uploaded, its text extracted (PDF via PyMuPDF), chunked,
   and each chunk embedded with a sentence-transformers model.
2. **Store.** Chunks and their vectors land in Postgres under the `pgvector`
   extension, alongside the document's metadata.
3. **Query.** An incoming question is embedded with the same model and matched
   by cosine similarity, returning the top passages with their scores.

Embedding is a local model — there is no external embedding API call, and no
document text leaves the machine. Unknown terms return an explicit empty result
set rather than a low-confidence guess.

## Requirements

- Docker + Docker Compose
- Internet access on first run to download the embedding model
  (`BAAI/bge-small-en-v1.5`)

## Run

```sh
docker compose up --build -d
curl -s http://localhost:8000/health   # -> {"status":"ok"}
```

Postgres (with `pgvector`) runs in the `db` container; tables are created
automatically on service startup.

## Authentication

Every route except `/health` and the docs is behind a bearer token, verified
per request against `auth-service GET /auth/verify`. Verification is not
cached, so a revoked token or a disabled user takes effect on the very next
call.

- Missing, invalid, expired, or revoked token → `401`, the operation is not
  performed.
- `auth-service` unreachable or misbehaving → `503`. The service **fails
  closed**: an authorization system that cannot answer is not one that should
  say yes.

## API

OpenAPI docs: `http://localhost:8000/docs`

| Method | Path                             | Purpose |
| ------ | -------------------------------- | ------- |
| POST   | `/documents`                     | Ingest (multipart file + metadata JSON). |
| GET    | `/documents`                     | List document metadata (pagination, `source`/`status` filters). |
| GET    | `/documents/{id}/content`        | The extracted text of one document. |
| GET    | `/documents/{id}/download`       | The original file. |
| PUT    | `/documents/{id}`                | Atomically replace a document (same id, no gap in queryability). |
| PATCH  | `/documents/{id}`                | Update metadata only, without re-ingesting. |
| DELETE | `/documents/{id}`                | Delete a document and its chunks. |
| POST   | `/query`                         | Semantic search: ranked passages with similarity scores, optional `top_k` and metadata `filters`. |
| GET    | `/health`                        | Liveness probe. |

`POST /documents` is synchronous: it responds `201` with a `document_id` only
once the document is actually queryable. There is no half-ingested state to
reason about.

## Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `DATABASE_URL` | `postgresql+psycopg://rag:rag@db:5432/rag` | Async psycopg3 SQLAlchemy URL. |
| `AUTH_SERVICE_URL` | `http://auth-service:8001` | Where tokens are introspected. |
| `EMBEDDING_MODEL` | `BAAI/bge-small-en-v1.5` | Sentence-transformers model. Changing it invalidates existing vectors. |
| `MAX_FILE_SIZE_MB` | `10` | Max upload size. |

## Tests

The suite runs in the container against the separate `rag_test` database
(created by `db/init.sql`) so test teardown never touches live data.

```sh
docker compose up -d db                    # Postgres must be healthy
docker compose run --rm \
  -v "$PWD/work-scope-rag":/worksrc:z \
  -v rag-model-cache:/root/.cache \
  -e DATABASE_URL=postgresql+psycopg://rag:rag@db:5432/rag_test \
  -w /worksrc \
  work-scope-rag python -m pytest -q
```

The model cache is mounted as a volume so repeated runs do not re-download the
embedding model.
