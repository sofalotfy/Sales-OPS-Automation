---

description: "Task list template for feature implementation"
---

# Tasks: RAG Document Service

**Input**: Design documents from `/specs/001-rag-document-service/`

**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md, contracts/

**Tests**: Test tasks are included because plan.md's Technical Context and Project Structure define a pytest + dockerized-Postgres test setup (unit/integration/contract suites).

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story. Code lives in the existing `work-scope-rag/` FastAPI scaffold (per plan.md Project Structure); the deprecated root-level `rag/` ChromaDB prototype is removed in the final phase.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

- **Service root**: `work-scope-rag/` (FastAPI backend web-service per plan.md)
- Tests live under `work-scope-rag/tests/` (contract / integration / unit)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization and basic structure

- [X] T001 Add `db` service using `pgvector/pgvector:pg16` image, a named volume, and wire `DATABASE_URL` env for `work-scope-rag` in docker-compose.yml (root: `docker-compose.yml`)
- [X] T002 [P] Add runtime + dev dependencies to `work-scope-rag/requirements.txt`: sqlalchemy>=2.0, psycopg[binary], pgvector, sentence-transformers, pymupdf, pydantic-settings, python-multipart, pytest, pytest-asyncio, httpx
- [X] T003 [P] Create `work-scope-rag/app/core/config.py` (pydantic-settings) reading `DATABASE_URL`, `EMBEDDING_MODEL` (default `BAAI/bge-small-en-v1.5`), `MAX_FILE_SIZE_MB` (default 10); update `work-scope-rag/.env.example` accordingly

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core infrastructure that MUST be complete before ANY user story can be implemented

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [X] T004 Create async SQLAlchemy engine + session factory using psycopg3 (`postgresql+psycopg://`) driver in `work-scope-rag/app/core/db.py`
- [X] T005 [P] Define `Document` SQLAlchemy model (id, title, source, file_type, content_hash, metadata JSONB, status, embedding_model, char_count, chunk_count, error, created_at, updated_at) in `work-scope-rag/app/models/document.py` per data-model.md
- [X] T006 [P] Define `Chunk` SQLAlchemy model with `Vector(384)` embedding column, FK `document_id` cascade, `UNIQUE(document_id, chunk_index)` in `work-scope-rag/app/models/chunk.py`
- [X] T007 Create schema bootstrap: register pgvector extension + `create_all` at app startup in `work-scope-rag/app/migrations/bootstrap.py` (v1 decision from plan.md: create_all instead of Alembic)
- [X] T008 [P] Implement embedding wrapper in `work-scope-rag/app/services/embeddings.py` loading the configured model once (lazy singleton), exposing `embed_texts(list[str]) -> list[list[float]]`; isolate model access per plan research.md
- [X] T009 [P] Set up test harness: `work-scope-rag/tests/conftest.py` with dockerized-Postgres fixture (pgvector image), async session fixture, empty-collection fixture; add pytest async config to `work-scope-rag/pyproject.toml` or `pytest.ini`
- [X] T010 Create shared validation/error helpers (reject 400/409/422 JSON `{"detail": ...}` responses, content-hash helper) in `work-scope-rag/app/core/errors.py`

**Checkpoint**: Foundation ready — DB connects, models create, embeddings load, user story implementation can begin

---

## Phase 3: User Story 1 - Document Ingestion (Priority: P1) 🎯 MVP

**Goal**: Accept plain-text, Markdown, and PDF company-scope documents; synchronously chunk, embed, and store them; respond with a document ID only once queryable (spec US1, FR-1/2/3/8/11/12).

**Independent Test**: POST a sample Markdown file to `/documents` and receive `201` with `status: "ready"` and `chunk_count > 0`, then immediately find its content via the query endpoint (quickstart scenario 1 & 2).

### Tests for User Story 1 ⚠️ (fail first, then implement)

- [X] T011 [P] [US1] Contract test for `POST /documents` (201, ready status, chunk_count>0) in `work-scope-rag/tests/contract/test_document_ingest.py`
- [X] T012 [P] [US1] Contract test for ingestion rejections (400 unsupported/empty, 409 duplicate, 422 too-large/image-only-PDF) in `work-scope-rag/tests/contract/test_document_validation.py`
- [X] T013 [P] [US1] Integration test: ingest → immediately queryable round-trip with dockerized Postgres in `work-scope-rag/tests/integration/test_ingestion_roundtrip.py`

### Implementation for User Story 1

- [X] T014 [P] [US1] Implement extraction service (text/Markdown passthrough, PyMuPDF PDF parsing, 10MB cap, image-only detection) in `work-scope-rag/app/services/extraction.py`
- [X] T015 [P] [US1] Implement configurable semantic chunker (similarity threshold 0.55, max 8 sentences, token-target ~500) in `work-scope-rag/app/services/chunking.py`
- [X] T016 [US1] Implement synchronous ingestion orchestrator (extract → chunk → embed outside txn → insert chunks + document in txn, content-hash dedup, processing/ready/failed transitions) in `work-scope-rag/app/services/ingestion.py` (depends on T005, T006, T008, T014, T015)
- [X] T017 [US1] Implement ingest Pydantic schemas in `work-scope-rag/app/schemas/document.py` and `POST /documents` multipart endpoint in `work-scope-rag/app/api/documents.py` (per contracts/document-api.md)
- [X] T018 [US1] Register documents router in `work-scope-rag/app/main.py`; add smoke fixtures `docs/smoke.md` (Markdown) and `docs/smoke.pdf` (text-layer PDF) at repo root `docs/`

**Checkpoint**: At this point, User Story 1 is fully functional and independently testable (MVP)

---

## Phase 4: User Story 2 - Contextual Query (Priority: P1)

**Goal**: Accept natural-language queries and return ranked, relevant passages with similarity scores and optional metadata filters; return an explicit empty set when nothing is relevant (spec US2, FR-4/5/9/14).

**Independent Test**: Seed the store (or reuse US1 ingest), then `POST /query` with a known-answer term and expect ranked results with descending `similarity_score`; an unrelated term returns `result_count: 0` (quickstart scenarios 2 & 3).

### Tests for User Story 2 ⚠️ (fail first, then implement)

- [X] T019 [P] [US2] Contract test for `POST /query` (ranked results, scores, filters, empty-result set, 422 validation) in `work-scope-rag/tests/contract/test_query.py`
- [X] T020 [P] [US2] Integration test retrieval correctness against a seeded corpus with metadata filters in `work-scope-rag/tests/integration/test_query_retrieval.py`

### Implementation for User Story 2

- [X] T021 [P] [US2] Implement retrieval service (embed query, cosine-distance ordered scan over `status=ready` docs only, top_k cap 20, metadata filters, relevance threshold → empty set) in `work-scope-rag/app/services/retrieval.py` (depends on T006, T008)
- [X] T022 [US2] Implement query Pydantic schemas in `work-scope-rag/app/schemas/query.py` and `POST /query` endpoint in `work-scope-rag/app/api/queries.py` (per contracts/query-api.md)
- [X] T023 [US2] Register queries router in `work-scope-rag/app/main.py`

**Checkpoint**: At this point, User Stories 1 AND 2 both work and are independently testable

---

## Phase 5: User Story 3 - Document Management (Priority: P3)

**Goal**: List, atomically replace, and remove previously ingested documents to keep the knowledge base current (spec US3, FR-6/7/13).

**Independent Test**: Create a document, replace it via `PUT /documents/{id}` verifying the same ID is returned and queries reflect only the new content (no gap), then `DELETE` it and confirm queries return zero results (quickstart scenarios 4 & 5).

### Tests for User Story 3 ⚠️ (fail first, then implement)

- [X] T024 [P] [US3] Contract test for `GET /documents` list (pagination + source/status filters), `PUT /documents/{id}` (atomic replace), `DELETE /documents/{id}` (404 on missing) in `work-scope-rag/tests/contract/test_document_management.py`
- [X] T025 [P] [US3] Integration test: atomic replace preserves document ID with no queryable gap, and delete removes chunks from search, in `work-scope-rag/tests/integration/test_document_management.py`

### Implementation for User Story 3

- [X] T026 [US3] Implement `GET /documents` list with pagination (limit 50/max 200, offset) returning metadata only (no chunk text) in `work-scope-rag/app/api/documents.py` (depends on T005)
- [X] T027 [US3] Implement `PUT /documents/{id}` atomic replace (single transaction: re-chunk/embed outside txn, cascade-delete old chunks, insert new, update counters) in `work-scope-rag/app/services/ingestion.py` + `work-scope-rag/app/api/documents.py` (depends on T016)
- [X] T028 [US3] Implement `DELETE /documents/{id}` cascading to chunks in `work-scope-rag/app/services/ingestion.py` + `work-scope-rag/app/api/documents.py`
- [X] T029 [US3] Add management response schemas (list item, pagination wrapper, replace/delete responses) and dedup 409 handling for updates in `work-scope-rag/app/schemas/document.py`

**Checkpoint**: All user stories are now independently functional

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Improvements that affect multiple user stories

- [X] T030 [P] Run quickstart validation scenarios end-to-end against `docker compose up` stack per `specs/001-rag-document-service/quickstart.md` (all 6 scenarios must pass)
- [X] T031 [P] Update `work-scope-rag/.env.example` and add minimal README for the service (run, configure, ingest/query examples)
- [X] T032 Remove deprecated ChromaDB prototype from repo root (`rag/`, stray `vector_db/` artifacts, `.gitignore` cleanup) per plan.md — confirm nothing imports it first
- [X] T033 [P] Load-validation smoke test (100 concurrent ingest / 500 concurrent queries without failure) in `work-scope-rag/tests/integration/test_load_smoke.py`
- [X] T034 Final consistency pass: verify implementation matches data-model.md (entities/state transitions), contracts (endpoints/errors), and spec acceptance scenarios; resolve any drift before `/speckit.converge`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately
- **Foundational (Phase 2)**: Depends on Setup completion — BLOCKS all user stories
- **User Stories (Phase 3+)**: All depend on Foundational completion
  - User stories proceed sequentially in priority order (US1 → US2 → US3)
- **Polish (Final Phase)**: Depends on all user stories being complete

### User Story Dependencies

- **User Story 1 (P1)**: Can start after Foundational (Phase 2) — no dependency on other stories
- **User Story 2 (P1)**: Requires Foundational vector store (T006, T008); independent of US1 implementation (seed data directly for tests)
- **User Story 3 (P3)**: Reuses US1 ingest pipeline (T016) for replace/delete — depends on US1 in the services layer, independently testable

### Within Each User Story

- Tests MUST be written and FAIL before implementation
- Models before services before endpoints
- Core implementation before integration/wiring
- Story complete before moving to next priority

### Parallel Opportunities

- All Setup tasks marked [P] run in parallel
- All Foundational tasks marked [P] run in parallel
- Within each story: contract + integration test tasks marked [P] run together
- Extraction (T014) and chunking (T015) run in parallel before ingestion orchestrator (T016)
- US2 retrieval service (T021) can be developed in parallel with US1 core since it only needs Foundation models

---

## Parallel Example: User Story 1

```bash
# Launch all tests for User Story 1 together (fail first):
Task: "Contract test POST /documents in tests/contract/test_document_ingest.py"
Task: "Contract test ingestion rejections in tests/contract/test_document_validation.py"
Task: "Integration round-trip test in tests/integration/test_ingestion_roundtrip.py"

# Launch extraction + chunking together:
Task: "Implement extraction service in app/services/extraction.py"
Task: "Implement chunking service in app/services/chunking.py"

# Then sequential: ingestion orchestrator -> POST endpoint -> router wiring
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL — blocks all stories)
3. Complete Phase 3: User Story 1 (Document Ingestion)
4. **STOP and VALIDATE**: ingest a sample Markdown + PDF, confirm `201 ready` and immediate queryability
5. Deploy/demo if ready

### Incremental Delivery

1. Setup + Foundational → Foundation ready
2. US1 Ingestion → Test → **MVP (delivers document store: add company-scope docs)**
3. US2 Query → Test → full RAG service value (retrieve relevant passages)
4. US3 Management → Test → keep knowledge base current (replace/delete/list)
5. Each story adds value without breaking previous stories

### Parallel Team Strategy

With multiple developers:

1. Team completes Setup + Foundational together
2. Once Foundational is done:
   - Developer A: User Story 1 (ingestion)
   - Developer B: User Story 2 (query/retrieval — needs only Foundation models)
   - Developer C waits for US1 services, then User Story 3 (management)
3. Stories complete and integrate independently

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to a specific user story for traceability
- Each user story is independently completable and testable
- Verify tests fail before implementing
- Test tasks included because plan.md defines the pytest contract/integration suite, not because the feature spec requested tests
- Commit after each task or logical group
- Stop at any checkpoint to validate the story independently
- Avoid: vague tasks, same-file conflicts, cross-story dependencies that break independence
- Deferred open items from research.md (chunk-size/threshold tuning on real corpus, create_all-vs-Alembic) are resolved here: v1 uses create_all; tuning knobs remain configurable via extraction/chunking service constants

---

## Phase 7: Convergence

- [X] T035 Fix `GET /documents` to return the total number of matching documents (not the current page item count) in `total` per contract: document-api.md (FR-7) (`partial`)
- [X] T036 Prepare a tiny known-answer golden set (5–10 Q/A pairs) and run the ≥80% retrieval-quality spot-check per SC-2 (quickstart.md Success Target) (`missing`)
- [X] T037 Return `400` (not `422`) for a missing `file` field on `POST /documents` and `PUT /documents/{id}` per contract: document-api.md error table (FR-8) (`partial`)
- [X] T038 Move chunking/embedding inference outside the open DB transaction in `ingest()`/`replace()` per plan: data-model.md Atomic Replace (embed outside txn) (`partial`)
- [X] T039 Remove the unused `MetadataModel` schema from `work-scope-rag/app/schemas/document.py` per plan: schemas structure (`unrequested`)

---

## Phase 8: Convergence

- [X] T040 Restore the `AsyncSession` import in `work-scope-rag/app/services/ingestion.py` (the `_reject_duplicate` annotation references it; currently undeclared and masked by `from __future__ import annotations`) per plan: services structure (`partial`)
- [X] T041 Wire the unused `DeleteResponse` schema (`work-scope-rag/app/schemas/document.py`) into the `DELETE /documents` endpoint via `response_model`, or remove it consistently with the T039 unused-schema cleanup per plan: schemas structure (`partial`)