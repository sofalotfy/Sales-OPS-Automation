# Quickstart: Rename Inquiry Widget to Inquiry Handler

**Purpose**: Validate the rename end-to-end after implementation: every user/operator-facing surface announces **Inquiry Handler**, the test console renders relabeled yet functional, the triage API is contract-identical, and the stack is healthy under the renamed service.

**Contracts**: [inquiry-web.md](./contracts/inquiry-web.md) · **Data model**: [data-model.md](./data-model.md) · **Spec**: [spec.md](./spec.md)

## Prerequisites

- Docker + Docker Compose (stack runs an `inquiry-handler` service on port 8003; `db`, `auth-service`, `work-scope-rag`, `dashboard` unchanged).
- Service code moved to `inquiry-handler/` with its `.env` carried over (`AUTH_API_URL`, `RAG_API_URL`, `SERVICE_USERNAME`/`SERVICE_PASSWORD`, `ZAI_API_KEY`, `ZAI_MODEL`, `BOOKING_URL`, `APP_URL=http://localhost:8003`).
- Local dev dependencies installed: `cd inquiry-handler && composer install`.
- Historical `specs/004-*` documents intentionally untouched (archival record; user decision).

## Setup

```bash
docker compose up --build -d
docker compose ps                       # expect an "inquiry-handler" service (container_name inquiry-handler), healthy
docker compose config | grep -i inquiry # expect only "inquiry-handler" — no "inquiry-widget" keys
curl -s http://localhost:8003/health    # inquiry-handler: expect {"status":"ok"}
```

## Validation Scenarios (map to spec Acceptance Scenarios / Success Criteria)

### 1. Rename sweep — no stale "widget" surfaces (US-2, FR-001, SC-1)

**Do**:

```bash
rg -i 'widget' inquiry-handler/README.md inquiry-handler/.env.example inquiry-handler/composer.json \
   inquiry-handler/routes/web.php inquiry-handler/resources/views docker-compose.yml \
   --glob '!storage/**' --glob '!vendor/**'
```

**Expected**: no matches in the service's own user/operator-facing files. The old name survives only in historical `specs/004-*` docs and (optionally) internal comments that are not gate-keeping (spec Edge Case).

### 2. Package and app identity renamed (FR-001, FR-004)

**Do**: `grep -i name inquiry-handler/composer.json ; grep APP_NAME inquiry-handler/.env.example`

**Expected**: `"name": "sales-ops/inquiry-handler"`, description/keywords without "widget"; `APP_NAME="Inquiry Handler"`.

### 3. Test console renders relabeled and functional (US-3, FR-003, SC-3)

**Do**: `curl -s http://localhost:8003/ | grep -iE 'Test Console|message|name|email'`

**Expected**: HTTP 200; the page heading shows **"Inquiry Handler — Test Console"** and the message + optional name/email inputs and submit control are present.

### 4. Triage API contract unchanged (US-1, FR-002, SC-2)

**Do** (with a `ready` document in the RAG corpus):

```bash
curl -s -X POST http://localhost:8003/inquiry/triage -H 'Content-Type: application/json' \
  -d '{"message":"Do you offer annual maintenance contracts for heating boilers?","name":"Jane Doe","email":"jane@example.com"}'
```

**Expected**: HTTP 200 with exactly one of `"disposition":"decline"|"escalate"|"booking"` plus `reply` and `context` — identical shape and status behavior to the pre-rename contract ([inquiry-web.md](./contracts/inquiry-web.md)).

### 5. Validation/error behavior unchanged (US-4, FR-002/FR-005)

**Do**: `-d '{"message":""}'` and `{"message":"hello","email":"bad-email"}`.

**Expected**: HTTP 422 with clear detail; no fabricated disposition anywhere (behavior identical to pre-rename).

### 6. Full test suite green (US-4, FR-008, SC-4)

**Do**:

```bash
cd inquiry-handler && composer install && ./vendor/bin/phpunit
```

**Expected**: all Feature + Unit tests pass (including the renamed `TestConsolePageTest` asserting the relabeled heading and form fields); zero secrets in output.

### 7. Stack healthy under new identity (US-1, FR-006, SC-5)

**Do**: `docker compose ps` and `curl -s http://localhost:8003/health`.

**Expected**: `inquiry-handler` container running and healthy; `/health` returns ok; ports and health ordering intact.

## Sign-off criteria (spec Success Criteria)

- SC-1: zero stale "Inquiry Widget" references in user/operator-facing surfaces (scenario 1–3).
- SC-2: triage API requests succeed with identical contract (scenario 4).
- SC-3: test console recognizable at a glance and returns a disposition on manual inquiry (scenario 3).
- SC-4: full automated suite passes with zero regressions (scenario 6).
- SC-5: renamed service healthy, `/health` ok (scenario 7).