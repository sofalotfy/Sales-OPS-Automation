# Implementation Plan: AI Sales Inquiry Triage

**Branch**: `004-ai-inquiry-triage` | **Date**: 2026-09-12 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/004-ai-inquiry-triage/spec.md`

## Summary

Add a **new independently deployable Laravel service** (`inquiry-widget`) that exposes a public chat-like widget where a visitor types a sales inquiry. The service:
1. **Extracts** the canonical message from the inbound payload via a dedicated extraction service (plain text today; schema-ready for external apps — spec FR-013). Optional **name** and **email** fields are accepted on the widget (FR-001/FR-002), validated, and preserved as context — they are NOT sent to the AI.
2. **Retrieves** the most relevant documents/chunks from the existing `work-scope-rag` `POST /query` vector endpoint (spec FR-003), holding the RAG call's bearer token via a dedicated service account authenticated against `auth-service` (server-side, never client-side).
3. **Hands** the question plus the retrieved context to the AI with an **explicit, isolated system-prompt injection point** (a `PromptBuilder` that keeps the fixed system prompt strictly separate from the visitor message and RAG content, which are injected as data — spec FR-004/FR-012). The AI provider is **Groq** hosting an open-source model, key + model via environment variables (spec FR-011; user-specified deviation from the constitution's Anthropic default).
4. **Routes** the outcome by strict JSON contract to one of **three separate handler classes in the same folder** — `DeclineHandler`, `EscalateHandler`, `BookingHandler` — via a `Dispatcher` (spec FR-005/FR-006/FR-007/FR-008, user's explicit design directive).
5. **Escalates by default** on any ambiguity or failure (unparseable AI output, RAG/AI/groq unreachable, missing key, no booking link configured) — spec FR-009/FR-010 and constitution principle III (human-in-the-loop). Nothing is silently dropped or guessed.
6. For **v1, escalation returns only a decision response** to the inquirer; no persistence, no webhook, no email, no CRM delivery. The real escalation mechanism is deferred (spec Clarifications).

No new database, no new data model, no queue: the service is a **stateless, pure HTTP consumer** (mirrors the `dashboard` service pattern). The extraction step leaves a clear seam for future structured payloads from external apps.

Research decisions (see [research.md](./research.md)): Laravel 13 on PHP 8.4 (mirrors dashboard as the user requested a Laravel service); the widget's public page + a single `POST /inquiry/triage` handler; service-account bearer token for RAG, cached in-memory and re-authenticated on `401`; Groq chat-completions via Laravel's `Http::` + `Http::fake()` tests; three disposition handlers + dispatcher; helper `MessageExtractor`; strict JSON output contract with escalate-by-default fallback; escalation = response-only for v1.

## Technical Context

**Language/Version**: PHP 8.4 for the new `inquiry-widget` service (Laravel 13.x, same as `dashboard`). No changes to the existing Python services (`work-scope-rag`, `auth-service`) — this feature is purely additive and consumes their existing endpoints.

**Primary Dependencies**: laravel/framework `^13`, phpunit (framework default) for tests. No Livewire — the widget is a single self-contained page (HTML form + fetch call), so Blade-only keeps it minimal (constitution V). Outbound HTTP via Laravel's built-in `Http` client (request/response fakes in tests). No new packages.

**Storage**: None — the service persists no business state. A file-backed Laravel session may be configured for framework hygiene but is not required (the widget is stateless). All business data remains in shared Postgres owned by `work-scope-rag`/`auth-service` (constitution IV). Escalation records for v1 are transitory in-memory DTOs only (Clarifications).

**Testing**: PHPUnit feature tests with `Http::fake()` for the three outbound integrations (auth-service login, `work-scope-rag POST /query`, Groq chat completions), one unit test file per `app/Triage/*` class, and an end-to-end validation guide in [quickstart.md](./quickstart.md).

**Target Platform**: Linux, Docker Compose (new `inquiry-widget` image alongside `db`, `auth-service`, `work-scope-rag`, `dashboard`).

**Project Type**: Web application (server-rendered frontend widget service consuming existing backend services over HTTP; one public page + one JSON endpoint).

**Performance Goals**: An inquiry returns a disposition in a user-perceived window under 60 s (spec SC-3); a typical turnaround is dominated by the Groq model call (seconds). RAG query round trip <1 s on the Compose network.

**Constraints**: HTTP-only between services (constitution I) — the service touches **no** database and **no** other service's internals. The RAG bearer token MUST stay server-side (never rendered, never logged). The API key MUST live in an environment variable, never committed or logged (spec FR-011). Every ambiguity or failure MUST escalate, never guess or drop (spec FR-009/FR-010, constitution III). Optional name/email fields are accepted by the widget for downstream use but are **not** sent to the AI — they are data, not instructions (FR-012, Clarifications).

**Scale/Scope**: Visitor-facing widget on a small sales site; a handful of concurrent users in v1; single tenant; one page + one endpoint. No persistence, no queue, no Redis.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Constitution Principle | Status | Notes |
|------------------------|--------|-------|
| I. Independently Deployable Services | PASS | New `inquiry-widget` image/service. Communicates ONLY over HTTP: `auth-service` (`POST /auth/login` for a service-account token), `work-scope-rag` (`POST /query`), and Groq (external HTTPS). Reads no database, no shared imports, no reaching into another service's tables. |
| II. API-First, FastAPI, `/health` | PASS (with justified deviation) | This is a UI service (widget), so it is exempt from the FastAPI backend default (the constitution's own carve-out for dashboards/UIs). Concrete stack deviates from the constitutional default (Streamlit) to **Laravel per the stakeholder's explicit request** — same justified deviation as `dashboard` (recorded in Complexity Tracking). The service still exposes `GET /health` for compose probes. |
| III. Human-in-the-Loop for Ambiguity (NON-NEGOTIABLE) | PASS | The design *implements* this principle: escalate-by-default on unparseable AI output, AI/RAG/provider unavailability, missing/expired key, or unfulfillable booking. Ambiguous or complex inquiries escalate with the raw inquiry + retrieved context preserved. Nothing is silently resolved or dropped (FR-007, FR-009, FR-010). For v1 the escalation action is: return the decision response to the inquirer only; no persistence, no webhook, no CRM (Clarifications). |
| IV. Data Model Is the Source of Truth | PASS | The service creates and owns **no** persistent business state. Triage outcomes are transient responses returned to the visitor. Escalation persistence/delivery to a human operator is explicitly deferred per provisional scope, principle V, and the stakeholder's Clarification ("we don't know what exactly it will do now"). The `Escalation Record` is a transitory in-memory DTO only — no table, no migration, no store. Existing document/user state stays exclusively in shared Postgres, owned by the RAG and auth services. |
| V. Simplicity and Provisional Scope | PASS | Smallest version serving the spec: one public page + one endpoint + extractor + 1 RAG call + 1 AI call + 3 handlers + strict JSON parse. No DB, no cache, no Redis, no queue. Stubs/simple TODOs over speculative generalization (e.g. structured-payload adapter is a stub for now). Escalation delivery mechanism deferred, not built speculatively (Clarifications). |
| Tech: Docker Compose orchestration | PASS | `inquiry-widget` added to compose (port 8003) with `depends_on: service_healthy` for `auth-service` and `work-scope-rag`; single `docker compose up` still brings up the whole stack. |
| Tech: Redis-backed task queue | PASS (not used) | The widget is synchronous (one Groq call in the request lifecycle); no background work in v1. Redis remains scoped to the async task queue. |
| Tech: PostgreSQL source of truth | PASS | No new database; the service relies solely on existing HTTP APIs. |
| Tech: LLM provider (constitution says Anthropic behind a thin wrapper) | PASS (deviation) | **Groq** open-source model per explicit user request. The thin-wrapper rule still holds: AI access is isolated behind `AiCallingService` so the provider can be swapped by changing env vars. Recorded in Complexity Tracking and the spec's deviation section. |

### Post-Design Re-check (Gate: re-evaluated after Phase 1)

Re-checked against the Phase 1 design artifacts (data-model.md, contracts/, quickstart.md):

| Principle | Status | Post-design verification |
|-----------|--------|--------------------------|
| I. Independently Deployable Services | PASS | `inquiry-widget/` is self-contained (own Dockerfile, no DB). `inquiry-web.md` documents the service's outward surface; `rag-query.md` and `ai-provider.md` document the only outbound links (`auth-service`, `work-scope-rag`, Groq). Both existing services are consumed, not modified. |
| II. API-First, FastAPI, `/health` | PASS (deviation justified) | `inquiry-widget` exposes `GET /health` (compose healthcheck) and a public web page + JSON endpoint; all backing services remain FastAPI. |
| III. Human-in-the-Loop | PASS | The `Dispatcher` maps only the three known dispositions; **any** parse failure, unknown disposition, or unfulfillable outcome routes to `EscalateHandler` with full context — verified per contract `ai-provider.md` and covered by feature tests. For v1 the escalation action is a decision response only; the mechanism is deferred (Clarifications). |
| IV. Data Model Source of Truth | PASS | data-model.md declares all entities transient; `Escalation Record` is a transitory in-memory DTO only (no persistence); the service owns no table. |
| V. Simplicity & Provisional Scope | PASS | Single Blade page + single JSON handler; no SPA, no Livewire, no queue, no Redis, no DB. Message-extraction is a stub seam for future structured schemas, not a speculative adapter framework. Escalation is response-only, not an elaborate operator pipeline (Clarifications). |
| Tech: Docker Compose | PASS | compose gains `inquiry-widget` (port 8003) with health ordering; env wiring (`AUTH_API_URL`, `RAG_API_URL`, `GROQ_API_KEY`, `GROQ_MODEL`, `SERVICE_USERNAME`, `SERVICE_PASSWORD`, `BOOKING_URL`) documented in quickstart and `.env.example`. |

No unresolved violations; the two justified deviations (Laravel UI stack; Groq LLM provider) are recorded below.

## Project Structure

### Documentation (this feature)

```text
specs/004-ai-inquiry-triage/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (/speckit.plan command)
├── data-model.md        # Phase 1 output (/speckit.plan command)
├── quickstart.md        # Phase 1 output (/speckit.plan command)
├── contracts/           # Phase 1 output (/speckit.plan command)
│   ├── inquiry-web.md   # widget UI + /inquiry/triage HTTP contract
│   ├── rag-query.md     # consumption of work-scope-rag POST /query
│   └── ai-provider.md   # Groq request shape + strict JSON output contract
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)

```text
inquiry-widget/                    # NEW independently deployable service
├── Dockerfile                     # multi-stage: npm build (styles) + composer install → php:8.4 runtime, `php artisan serve --host=0.0.0.0 --port=8003`
├── .env.example                   # GROQ_API_KEY, GROQ_MODEL, AUTH_API_URL=http://auth-service:8001, RAG_API_URL=http://work-scope-rag:8000, SERVICE_USERNAME/SERVICE_PASSWORD, BOOKING_URL
├── composer.json                  # laravel/framework ^13, phpunit
├── artisan
├── app/
│   ├── Http/Controllers/
│   │   ├── HealthController.php       # GET /health
│   │   └── InquiryController.php      # GET / (widget page) + POST /inquiry/triage
│   ├── Services/
│   │   ├── AuthApiClient.php          # POST /auth/login (service account) → LoginResponse
│   │   ├── RagApiClient.php           # POST /query with bearer token from ServiceToken
│   │   └── AiCallingService.php       # calls Groq; builds system prompt via PromptBuilder; parses JSON
│   ├── Triage/
│   │   ├── MessageExtractor.php       # extracts canonical message from raw payload (plain text now; structured-schema stub)
│   │   ├── PromptBuilder.php          # EXPLICIT prompt-injection point: fixed system prompt + user inquiry + RAG docs as data
│   │   ├── Dispatcher.php             # disposition string → handler class (strict map; default → EscalateHandler)
│   │   └── Handlers/
│   │       ├── DeclineHandler.php     # polite out-of-scope decline reply
│   │       ├── EscalateHandler.php    # preserves inquiry + retrieved context for a human (default path); v1 returns decision only
│   │       └── BookingHandler.php     # booking disposition → BOOKING_URL (config), else escalate
│   └── Support/ServiceToken.php       # in-memory cached bearer token; re-auth on 401/expiry
├── routes/web.php                 # /health, GET / (widget), POST /inquiry/triage
├── resources/views/
│   ├── layouts/app.blade.php
│   └── inquiry/index.blade.php    # the widget (message field + optional name/email fields + fetch to /inquiry/triage + result area)
├── public/                        # built css
└── tests/
    ├── Feature/
    │   ├── WidgetPageTest.php     # GET / renders the widget (message, name, email fields)
    │   └── InquiryTriageTest.php  # decline/escalate/booking flows + escalate-by-default failures + contact field handling (Http::fake())
    └── Unit/
        ├── MessageExtractorTest.php   # plain text extraction, name/email passthrough, validation
        ├── PromptBuilderTest.php      # proves system prompt separate from injected inquiry+docs; contact fields NOT present in prompt
        ├── DispatcherTest.php         # unknown/unparseable disposition → escalate
        └── ServiceTokenTest.php       # token caching + re-auth on 401

docker-compose.yml                # + inquiry-widget service (port 8003, env URLs, depends_on health)
```

**Structure Decision**: A dedicated `inquiry-widget/` Laravel 13 web service (own Dockerfile) mirroring the project's one-capability-per-service layout, replicating the `dashboard/` service exactly where the stack is identical, and adding the feature-specific `app/Triage/` layer: separate handler classes per user's explicit design directive, `MessageExtractor` for payload normalization, and `PromptBuilder` as the single, clearly-visible system-prompt injection point. The service consumes only existing endpoints (`auth-service POST /auth/login`, `work-scope-rag POST /query`, Groq HTTPS) — no RAG or auth-service code changes are needed. Optional name/email fields are accepted by the widget contract, validated by the controller, carried as context, but never sent to the AI (data, not instructions).

## Complexity Tracking

> Constitution Check has two justified deviations to record.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Widget stack deviates from the constitutional default: **Streamlit → Laravel** | The stakeholder explicitly requested a Laravel service (same as the dashboard); Gate II already carves out UIs as not-API, and Laravel matches the existing dashboard team skill set across the repo | Sticking with the constitutional Streamlit default would ignore the explicit stakeholder request; embedding the widget into the existing dashboard service would entangle two deployments against constitution I |
| LLM provider deviates from the constitutional default: **Anthropic → Groq** | The stakeholder explicitly requested Groq working with an open-source model, key supplied via env var | Silently keeping Anthropic would ignore the explicit request; the thin-wrapper isolation (`AiCallingService`) preserves the constitution's swap-able provider rule either way |
| *(recorded, not counted)* Service-account token for RAG | `work-scope-rag POST /query` requires a valid bearer token; a public widget has no human session to borrow one from | Embedding credentials into the RAG call without auth (`allow no-token queries`) would weaken the existing API contract; reusing a human admin session would couple the widget to operator accounts |
| *(recorded, not counted)* Escalation = response-only, deferred delivery (v1 Clarification) | Stakeholder explicitly ruled out any persistence, webhook, email, or CRM delivery for v1 — the system simply returns the escalation decision to the inquirer | Implementing a full operator notification/CRM pipeline now would violate constitution V (smallest version that satisfies the spec) and the stakeholder's instruction; the mechanism is deferred with a clear TODO |

*(Structure Decision, Technical Context, and the above matrix form the complete /speckit.plan output for this feature. tasks.md is generated by /speckit.tasks.)*