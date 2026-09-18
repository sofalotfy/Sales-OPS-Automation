# Quickstart: Scope Gate Middleware

**Feature**: [007-scope-gate-middleware](spec.md) · Validation guide (Phase 1 of `/speckit.plan`).

These scenarios prove the feature works end-to-end. Implementation details live in `tasks.md` (created by `/speckit.tasks`).

## Prerequisites

- Docker Compose stack running: `docker compose up --build -d` (db, auth-service, work-scope-rag, inquiry-handler, dashboard).
- Migration auto-applies in the inquiry-handler entrypoint (`php artisan migrate --force`): adds `scope_check_outcome`, `scope_check_reason`, `refusal` to `classification_results`.
- Gate is on by default (`SCOPE_GATE_ENABLED=true`). Env knobs: `RAG_TOP_K` (gate retrieval depth, default 5), `ZAI_API_KEY`/`ZAI_MODEL` (already in use by classification). An empty/missing `ZAI_API_KEY` exercises the indeterminate/fail-open path.
- A retrieval corpus loaded in work-scope-rag that reflects the company scope (`COMPANY_SCOPE` statement).

## Automated validation

```bash
cd inquiry-handler && composer test
```

Expected: all feature/unit tests pass, including the new `ScopeGateTest` and `ScopeCheckServiceTest`, and the untouched feature-006 tests remain green.

## Manual validation

### 1. In-scope inquiry → classified normally (SC-002, FR-012)

```bash
curl -s -X POST http://localhost:8003/inquiry/triage \
  -H 'Content-Type: application/json' \
  -d '{"message": "Do you build enterprise web applications for B2B companies?"}'
```

Expect: `200`, normal classification response (feature-006 shape), and `context.scope_check.outcome = "accept"`. Contract: [contracts/inquiry-web.md](contracts/inquiry-web.md).

### 2. Out-of-scope inquiry → refusal with reason (SC-001, FR-006)

```bash
curl -s -X POST http://localhost:8003/inquiry/triage \
  -H 'Content-Type: application/json' \
  -d '{"message": "Can you fix my washing machine?"}'
```

Expect: `200`, `classification = "disqualify"`, `factor_scores = {}`, `reply` is a refusal that explains the reason, and `context.scope_check.outcome = "decline"`. The classification engine never runs.

### 3. Declined record is persisted for review (SC-006, SC-007)

```bash
docker compose exec db psql -U rag -d inquiry_handler -c \
  "select id, inquiry_message, scope_check_outcome, refusal from classification_results order by id desc limit 5;"
```

Expect: the decline row carries `scope_check_outcome='decline'`, a `refusal`, and `scope_check_reason`; the in-scope row carries `scope_check_outcome='accept'`.

### 4. RAG down → fails open (SC-003, FR-007)

```bash
docker compose stop work-scope-rag
```

Submit an in-scope inquiry. Expect: still a `200` triage response with `context.scope_check.outcome = "indeterminate"` — never a fabricated decision, never an error. Restart:

```bash
docker compose start work-scope-rag
```

### 5. AI credentials missing → fails open (SC-003, FR-007)

Unset `ZAI_API_KEY` for inquiry-handler and submit an inquiry. Expect: `200` triage response, `scope_check.outcome = "indeterminate"`. Restore the key and restart.

### 6. Kill switch bypasses the gate

Set `SCOPE_GATE_ENABLED=false`, restart inquiry-handler, submit an out-of-scope inquiry. Expect: the gate is skipped — classification runs exactly as before this feature (no `scope_check` marker). This is the documented disable path for incidents.

## Contract & data references

- [contracts/inquiry-web.md](contracts/inquiry-web.md) — the scope-gate additions to the triage endpoint (refusal shape, `scope_check` marker, outcomes).
- [data-model.md](data-model.md) — the `classification_results` scope-check columns and the decline/accept/indeterminate write paths.
- [spec.md](spec.md) — FR-001…FR-012, SC-001…SC-007, and the recorded clarifications.