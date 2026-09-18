# Quickstart: AI Sales Inquiry Triage

**Purpose**: Validate the feature end-to-end after implementation: the widget page renders, a real inquiry is triaged through RAG + Groq into one of the three dispositions, escalate-by-default holds on failures, and the service is healthy in the stack.

**Contracts**: [inquiry-web.md](./contracts/inquiry-web.md) · [rag-query.md](./contracts/rag-query.md) · [ai-provider.md](./contracts/ai-provider.md) · **Data model**: [data-model.md](./data-model.md) · **Upstreams**: [auth-api.md](../../002-api-user-auth/contracts/auth-api.md) · [document-api.md](../../001-rag-document-service/contracts/document-api.md)

## Prerequisites

- Docker + Docker Compose (stack gains an `inquiry-widget` service on port 8003; `db`, `auth-service`, `work-scope-rag`, `dashboard` unchanged).
- `inquiry-widget/.env`: `GROQ_API_KEY` (secret), `GROQ_MODEL` (e.g. `llama-3.3-70b-versatile`), `AUTH_API_URL=http://auth-service:8001`, `RAG_API_URL=http://work-scope-rag:8000`, `SERVICE_USERNAME` / `SERVICE_PASSWORD` (a user registered in `auth-service` that the RAG guard accepts), `BOOKING_URL`, and `APP_URL=http://localhost:8003`.
- The RAG corpus must contain at least one `ready` document relevant to the test inquiry (seed via the [document pipeline](../../001-rag-document-service/quickstart.md)).
- Compose wiring: `inquiry-widget` `depends_on` `auth-service` and `work-scope-rag` (`condition: service_healthy`), port `8003:8003`.

## Setup

```bash
docker compose up --build -d
curl -s http://localhost:8003/health     # inquiry-widget: expect {"status":"ok"}
curl -s http://localhost:8000/health     # work-scope-rag: {"status":"ok"}
curl -s http://localhost:8001/health     # auth-service: {"status":"ok"}
```

Open `http://localhost:8003` — the widget with a message field, optional name/email fields, and submit control renders.

## Validation Scenarios (map to spec Acceptance Scenarios)

### 1. Widget page renders with all fields (US-1, FR-001, FR-002)

**Do**: `curl -s http://localhost:8003/ | grep -iE 'message|name|email|inquiry'`

**Expected**: the widget HTML with a required message input, optional name input, optional email input, and submit control; HTTP 200.

### 2. In-scope inquiry with optional contact fields → disposition (US-1, FR-001..FR-005)

**Do** (with a `ready` document in the corpus):

```bash
curl -s -X POST http://localhost:8003/inquiry/triage \
  -H 'Content-Type: application/json' \
  -d '{"message":"Do you offer annual maintenance contracts for heating boilers?","name":"Jane Doe","email":"jane@example.com"}'
```

**Expected**: HTTP 200 with exactly one of `"disposition":"decline"`, `"escalate"`, or `"booking"` plus `reply` and `context`. `context.inquiry` reflects the submitted name and email. For a qualified, meeting-ready inquiry, `"booking"` and a usable `BOOKING_URL` in `reply` (SC-3).

### 3. In-scope inquiry without contact fields → same result (US-1, US-1 acceptance 5)

**Do**: same as scenario 2 but without `name`/`email`.

```bash
curl -s -X POST http://localhost:8003/inquiry/triage \
  -H 'Content-Type: application/json' \
  -d '{"message":"Do you offer annual maintenance contracts for heating boilers?"}'
```

**Expected**: HTTP 200; same `disposition` as scenario 2 (contact fields do not affect triage); `context.inquiry.name` and `context.inquiry.email` are absent/null.

### 4. Grounded in retrieved knowledge (US-1, SC-2)

**Do**: repeat scenario 2 and inspect `context.retrieved_context.results[]`.

**Expected**: `result_count >= 1` with real titles/sources when the corpus is on-topic; the `reply` reflects retrieved content (FR-003 grounded answer).

### 5. Out-of-scope inquiry → decline (US-4, FR-006, SC-4)

**Do:**

```bash
curl -s -X POST http://localhost:8003/inquiry/triage -H 'Content-Type: application/json' \
  -d '{"message":"Can you fix my bicycle?"}'
```

**Expected**: HTTP 200, `"disposition":"decline"`, courteous reply, and **no** booking link and no escalation (SC-4).

### 6. Ambiguous / failure → escalate-by-default (US-2, FR-007/FR-010, SC-2)

**Path A — vague inquiry**: submit `"somebody please help me with something"` (or any intentionally ambiguous text). **Expected**: `escalate`, `context.inquiry` present.

**Path B — RAG down**: `docker compose stop work-scope-rag`, submit a valid inquiry. **Expected**: HTTP 200 `escalate` (context = extracted inquiry) or a clean `503` per [inquiry-web.md](./contracts/inquiry-web.md) — never a fabricated disposition. Restart: `docker compose start work-scope-rag`.

**Path C — Groq down/bad key**: set `GROQ_API_KEY=garbage`, restart, submit. **Expected**: `escalate` (or `503` if context can't be preserved); the key is never echoed in logs. Restore the key and restart.

### 7. Booking without configured link → escalate (FR-009, SC-5)

**Do**: empty `BOOKING_URL` (unset), restart, prompt the AI toward booking (qualified, meeting-ready inquiry). **Expected**: `escalate`, never a broken link.

### 8. Invalid input rejected (US-1 edge, FR-002)

**Do**: `-d '{"message":""}'` and `-d '{"message":"   "}'` and a >4000-char message.

**Expected**: HTTP 422 each, no RAG/AI call, clear detail messages.

### 9. Malformed email rejected (Edge Case, FR-002)

**Do**: `-d '{"message":"hello","email":"bad-email"}'` and `-d '{"message":"hello","email":"user@"}'`.

**Expected**: HTTP 422 each with an email-format validation error; `message`-only submission is accepted.

### 10. Escalate only returns the decision response (US-2, v1 Clarification)

**Do**: after a successful escalation scenario (scenario 6A), inspect the full response.

**Expected**: `disposition` is `"escalate"`, `reply` contains a message indicating the decision was taken; the response contains no persistence/persistence IDs/escalation record (v1 performs no further action per Clarifications).

## Sign-off criteria (spec Success Criteria)

- SC-1: every valid inquiry → exactly one of the three dispositions (scenarios 2–5).
- SC-2: ambiguous or failing triages escalate, never guess (scenarios 6/7).
- SC-3: booking reaches the visitor ≤ 60 s (scenario 2/4).
- SC-4: out-of-scope → decline only (scenario 5).
- SC-5: booking never references an unconfigured link (scenario 7).
- SC-6: `GROQ_API_KEY`/`GROQ_MODEL` are env-configurable; a repo scan shows no secrets (scenario 6C + code review).