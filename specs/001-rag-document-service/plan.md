# Implementation Plan: RAG Document Service

**Branch**: `001-rag-document-service` | **Date**: 2026-09-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-rag-document-service/spec.md`

## Summary

Build the RAG document service: a FastAPI HTTP service that ingests plain-text, PDF, and Markdown company-scope documents, chunks and embeds them, stores embeddings in the shared PostgreSQL/pgvector database, and exposes APIs to ingest (synchronously), replace, delete, list, and similarity-query documents. Consumers are internal systems (the triage agent and downstream orchestration). No user-facing UI.

The pre-existing `work-scope-rag/` FastAPI scaffold is the home for this service; the standalone `rag/` ChromaDB prototype is superseded reference code and is decommissioned as part of implementation.

## Technical Context

**Language/Version**: Python 3.12 (matches existing `work-scope-rag/Dockerfile`)

**Primary Dependencies**: fastapi, uvicorn, sqlalchemy (2.0 async), psycopg[binary] (psycopg3), pgvector, sentence-transformers (`BAAI/bge-small-en-v1.5`), PyMuPDF, pydantic, pytest

**Storage**: PostgreSQL (shared, added to Docker Compose) with `pgvector` extension. RAG vectors live in the same Postgres (constitution-mandated; no separate vector DB at this scale). Exact nearest-neighbor scan, no HNSW/ivfflat index until corpus exceeds ~50k rows.

**Testing**: pytest + pytest-asyncio + httpx (TestClient), factory-based fixtures (testcontainers or dockerized Postgres for integration).

**Target Platform**: Linux, Docker Compose (existing `work-scope-rag` service image)

**Project Type**: Backend web-service (HTTP API)

**Performance Goals**: 100 concurrent ingestion requests and 500 concurrent queries without failure; query p95 latency below 1 second on the small corpus; standard-length document ingested (becomes queryable) within 10 seconds.

**Constraints**: Single `docker compose up` stack; service depends only on Postgres connection string via `.env`; embedding/LLM access isolated behind a thin wrapper (swappable); no background queue used (ingestion is synchronous by clarification — Redis queue reserved for future async needs under Gate V simplicity).

**Scale/Scope**: Small corpus — tens to a few hundred documents; single tenant; internal-only consumers; a single shared Postgres.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Constitution Principle | Status | Notes |
|------------------------|--------|-------|
| I. Independently Deployable Services | PASS | `work-scope-rag` stays an independent service with its own Dockerfile; communicates over HTTP only. |
| II. API-First, FastAPI, `/health` | PASS | FastAPI service with `/health`; API-first contracts designed in Phase 1. |
| III. Human-in-the-Loop for Ambiguity | PASS | RAG service returns explicit empty/no-match results (with clear flag) so the triage layer can escalate to a human; no silent auto-resolution here. |
| IV. Data Model Is the Source of Truth | PASS | Documents and chunks live in shared Postgres (document + chunk tables); ChromaDB prototype (`rag/`) is retired — no data scattered in service-local vector files. |
| V. Simplicity & Provisional Scope | PASS | Smallest version serving the spec: synchronous ingestion, no queue, no ANN index, exact scan. Provisional items (model choice, tuning) recorded for later validation. |
| Tech: Docker Compose orchestration | PASS | postgres service added to compose; app joins network. |
| Tech: pgvector backs RAG vector store | PASS | vectors stored in Postgres/pgvector; no separate vector DB. |
| Tech: Redis-backed task queue | PASS (not used) | Ingestion is synchronous by clarified FR-11; no background jobs needed in v1. |
| Tech: Anthropic LLM provider behind wrapper | PASS (not used) | RAG service has no generation step; edge upgrade path retains isolated embedding wrapper. Claude is generation-only. |

### Post-Design Re-check (Gate: re-evaluated after Phase 1)

Re-checked against the Phase 1 design artifacts (data-model.md, contracts/, quickstart.md):

| Principle | Status | Post-design verification |
|-----------|--------|--------------------------|
| I. Independently Deployable Services | PASS | One service image; talks to Postgres only via `DATABASE_URL`; no import coupling across service boundaries. |
| II. API-First, FastAPI, `/health` | PASS | Contracts define the API surface; `/health` present; no consumer-facing UI. |
| III. Human-in-the-Loop | PASS | Query API returns explicit empty/no-match results and `detail` errors so the triage layer can escalate; ingestion marks failed documents without silent drops. |
| IV. Data Model Source of Truth | PASS | `documents` + `chunks` in shared Postgres; ChromaDB prototype retired; single-transaction replace keeps state atomic. |
| V. Simplicity & Provisional Scope | PASS | No ANN index, no queue, no OCR, no separate vector DB; tuning knobs left configurable rather than hard-coded decisions around uncollected sales-team requirements. |
| Tech: pgvector backs RAG vector store | PASS | `chunks.embedding` is `vector(384)` in Postgres — no separate vector store. |
| Tech: Docker Compose | PASS | `db` service added to compose; single `docker compose up` brings the stack up. |

No violations; complexity tracking table remains unfilled.

## Project Structure

### Documentation (this feature)

```text
specs/001-rag-document-service/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (/speckit.plan command)
├── data-model.md        # Phase 1 output (/speckit.plan command)
├── quickstart.md        # Phase 1 output (/speckit.plan command)
├── contracts/           # Phase 1 output (/speckit.plan command)
│   ├── document-api.md
│   └── query-api.md
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)

```text
work-scope-rag/                    # the RAG service (existing scaffold)
├── Dockerfile                     # existing, extended with runtime deps
├── requirements.txt               # extended with sqlalchemy, psycopg, pgvector, sentence-transformers, PyMuPDF
├── .env.example                   # DATABASE_URL, EMBEDDING_MODEL, MAX_FILE_SIZE_MB
├── app/
│   ├── __init__.py
│   ├── main.py                    # FastAPI app, router wiring, /health
│   ├── api/
│   │   ├── __init__.py
│   │   ├── documents.py           # POST/GET/PUT/DELETE documents
│   │   └── queries.py             # POST /query
│   ├── core/
│   │   ├── __init__.py
│   │   ├── config.py              # settings from env
│   │   └── db.py                  # async engine + session factory
│   ├── models/
│   │   ├── __init__.py
│   │   ├── document.py            # documents table
│   │   └── chunk.py               # chunks table (embedding vector)
│   ├── schemas/
│   │   ├── __init__.py
│   │   ├── document.py            # ingest/replace/list/get response models
│   │   └── query.py               # query request/response models
│   ├── services/
│   │   ├── __init__.py
│   │   ├── embeddings.py          # thin embedding wrapper (swappable)
│   │   ├── extraction.py          # text/Markdown/PDF extraction + size checks
│   │   ├── chunking.py            # semantic chunker ported/adapted
│   │   ├── ingestion.py           # ingest + atomic-replace orchestration
│   │   └── retrieval.py           # similarity search + filters + scores
│   └── migrations/                # SQLAlchemy DDL/bootstrap (create_all or Alembic)
└── tests/
    ├── __init__.py
    ├── conftest.py
    ├── unit/                      # extraction, chunking, validation
    ├── integration/               # ingestion → query round-trips against Postgres
    └── contract/                  # HTTP contract tests for /contracts

rag/                               # DEPRECATED ChromaDB prototype — removed in implementation
docker-compose.yml                 # add `db` (postgres:16-pgvector) service + volume
```

**Structure Decision**: Single backend service under the existing `work-scope-rag/` scaffold. This matches the constitution ("one service at a time, starting with the RAG/triage system") and the existing Dockerfile/compose wiring. The deprecated `rag/` package at repo root is replaced by `work-scope-rag/app/services/*` so the service is self-contained per Gate I.

## Complexity Tracking

> Not required — Constitution Check passes with no violations.