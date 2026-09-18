# Data Model: AI Sales Inquiry Triage

**Date**: 2026-09-12
**Source**: [spec.md](./spec.md) (Key Entities), [research.md](./research.md)

The inquiry widget introduces **no new persistent data**. Per constitution Gate IV the source of truth remains the shared PostgreSQL server, and per Gate I the service is a HTTP-only consumer. All triage objects documented below are **transient** — they exist for the lifetime of a single `POST /inquiry/triage` request and are discarded. Escalation persistence / delivery to a human is **explicitly out of scope for v1** (stakeholder instruction + provisional scope, principle V; see spec Clarifications and research §7).

The service's only long-lived state is a **bearer token cached in memory** (`app/Support/ServiceToken.php`) for the service account — revocable and re-fetched on `401`, never persisted.

## Entity: `inquiry_payload` (transient — per request)

The raw inbound payload as received by `POST /inquiry/triage` (see [contracts/inquiry-web.md](./contracts/inquiry-web.md)).

| Field | Type | Required | Constraints |
|-------|------|----------|-------------|
| `message` | STRING | yes | non-blank after trim; length ≤ `MESSAGE_MAX_LENGTH` (default 4000) |
| `name` | STRING | no | optional display name; max 255 chars if provided |
| `email` | STRING | no | optional contact email; valid email format if provided |

## Entity: `inquiry` (transient — per request)

The canonical, normalized message extracted from the inbound payload by `MessageExtractor` (FR-013). Contact fields (`name`, `email`) are carried alongside the message in a `context` companion object — they are NOT sent to the AI; they are preserved so a future escalation/notification mechanism can use them if desired.

| Field | Type | Constraints / Notes |
|-------|------|---------------------|
| `message` | STRING | Non-empty after trim, subject to a length cap (see validation below). This is the ONLY canonical field the AI reasons about. |
| `name` | STRING | Optional; passed through from `inquiry_payload` unchanged. |
| `email` | STRING | Optional; passed through from `inquiry_payload` unchanged. Valid format if present. |
| `raw_payload` | ARRAY | The original request body (kept for traceability). |

**Validation rules** (`MessageExtractor` / `InquiryController`):
- `message` must be present and non-blank after trim → validation error `422` otherwise.
- Length cap: message trimmed length ≤ `MESSAGE_MAX_LENGTH` (configurable; default 4000 chars) — guards the AI/RAG call, reflects spec Edge Case "input exceeds a reasonable length limit".
- `email`, if present, must be a valid email format; otherwise validation error `422` with a clear message. (Edge Case: malformed optional email.)
- `name`: optional, max 255 chars if provided.

**Extraction seam** (research §6): for v1 the extraction branch is `{"message": "...", "name?": "...", "email?": "..."}` (the widget). A future external-app branch (predefined schema) adds another branch in the same `extract()` method — marked with a TODO, no speculative adapters.

## Entity: `retrieved_context` (transient — per request)

Grounding returned by `work-scope-rag POST /query` (contract: [rag-query.md](./contracts/rag-query.md)) — passed to `PromptBuilder` verbatim as data (FR-003, FR-004, FR-012).

| Field | Type | Notes |
|-------|------|-------|
| `results[]` | ARRAY | RAG `QueryResult[]`: `{rank, text, similarity_score, document_id, source, title, chunk_index}`. Only `status=="ready"` documents are returned by the RAG service. |
| `result_count` | INTEGER | Number of results above the RAG relevance threshold (may be 0 — handled as "no context", still triaged). |

An empty `results` list still goes to the AI with a "no retrieved documents" block; the AI should escalate rather than invent product facts on no context (the system prompt instructs this).

## Entity: `triage_outcome` (transient — per request)

The uniform result object every handler returns (`TriageResult`).

| Field | Type | Notes |
|-------|------|-------|
| `disposition` | ENUM | `decline` \| `escalate` \| `booking`. Only these three (FR-005). |
| `reply` | STRING | Visitor-facing message returned by the widget. |
| `reasoning` | STRING | Internal justification (may be logged; not necessarily rendered to visitor). |
| `context` | ARRAY | `{inquiry: {message, name?, email?}, retrieved_context}` — preserved for all dispositions; for v1, only returned in the response (not persisted). |

**Failure → escalate-by-default (constitution III, FR-009/FR-010):** any of the following lands on `EscalateHandler` with `context` fully preserved in the response:
- AI output unparseable / not valid JSON / `disposition` not one of the three enums;
- Groq HTTP error, timeout, missing/invalid `GROQ_API_KEY`;
- RAG `POST /query` error or `401` not recoverable after token re-auth;
- booking disposition chosen but `BOOKING_URL` not configured (FR-009 — escalate rather than broken link).

## Config (env) — not persisted, but the service's operational "model"

| Variable | Purpose | Example / Default |
|----------|---------|-------------------|
| `GROQ_API_KEY` | Groq API key (FR-011; never committed/logged) | *(secret)* |
| `GROQ_MODEL` | Open-source model hosted on Groq | `llama-3.3-70b-versatile` |
| `AUTH_API_URL` | auth-service base URL | `http://auth-service:8001` |
| `RAG_API_URL` | work-scope-rag base URL | `http://work-scope-rag:8000` |
| `SERVICE_USERNAME` / `SERVICE_PASSWORD` | Service-account credentials for the RAG bearer token (research §2) | *(secret)* |
| `BOOKING_URL` | Booking link for the booking disposition (FR-008; absent ⇒ any booking → escalate) | `https://calendly.com/...` |
| `MESSAGE_MAX_LENGTH` | Inquiry length cap | `4000` |
| `RAG_TOP_K` | `top_k` passed to `POST /query` | `5` |

The model of the whole feature is: `query(text) → context + disposition + reply`, with no rows, no tables, and no migrations.