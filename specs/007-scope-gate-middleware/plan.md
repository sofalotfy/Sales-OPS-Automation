# Implementation Plan: Scope Gate Middleware

**Branch**: `007-scope-gate-middleware` | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/007-scope-gate-middleware/spec.md`

## Summary

A **scope gate middleware** is placed in front of `POST /inquiry/triage` in inquiry-handler. Before the classification flow runs, the gate checks the inquiry against the served scope via the existing RAG retrieval + the existing isolated AI caller: RAG connects to work-scope-rag, then a fixed binary-scope prompt grounded on `COMPANY_SCOPE` asks the AI for `{in_scope, reason}`. Outcome is one of **accept** (pass through to classification), **decline** (short-circuit, record a refusal in `classification_results`, and return a same-shape refusal response with `classification="disqualify"`, empty factor scores, and `context.scope_check.outcome="decline"`), or **indeterminate** (any upstream failure, missing grounding, or unparseable AI output → **fail open**: the full classification flow runs, never a fabricated or dropped decision). Every screened inquiry records `scope_check_outcome` / `scope_check_reason` (and `refusal` on declines) on its classification-result row. A `SCOPE_GATE_ENABLED` kill switch bypasses the gate entirely. Behavior on the accept path and for invalid payloads is identical to the pre-feature flow.

## Technical Context

**Language/Version**: PHP 8.4 (runtime images `php:8.4-cli`), `php:^8.3` constraint — inquiry-handler (Laravel 13).

**Primary Dependencies**: No new packages. `laravel/framework ^13.17` (Eloquent, `Http` facade, middleware/Kernel), `pdo_pgsql` (already in Docker) for the scoped store, PHPUnit 12 for tests. Reuses existing `AiCallingService`, `RagApiClient`, `MessageExtractor`, `config/services.php` (`services.zai.*`), `services.company_scope`.

**Storage**: PostgreSQL 16 (shared `db` service), scoped database `inquiry_handler`. An **additive migration** on `classification_results` adds `scope_check_outcome` (`accept`|`decline`|`indeterminate`), `scope_check_reason` (text), `refusal` (text). No new tables. Tests run on SQLite `:memory:` (`RefreshDatabase`), never the scoped store.

**Testing**: PHPUnit 12 (`composer test`, i.e. `php artisan test`). New feature test `ScopeGateTest` (accept/decline/indeterminate/invalid-payload scenarios via `Http::fake` + `UpstreamStubs`) and unit tests `ScopeCheckServiceTest` / `ScopeVerdictTest`; untouched feature-006 tests stay green. No new testing framework.

**Target Platform**: Linux, Docker Compose network (internal DNS `inquiry-handler:8003`, `work-scope-rag`, `auth-service`, `db`).

**Project Type**: HTTP web service — a stateless classification API (inquiry-handler) with an added pre-screen middleware.

**Performance Goals**: SC-004 — gate executes within a few seconds (one RAG query + one AI call, i.e. feedback within the same waiting time as classification). No stricter latency target; per-call latency deferred to load testing per the clarification session.

**Constraints**: No shared in-process code across services; no direct cross-service database access (constitution I); the gate never fabricates or drops an inquiry (FR-007); invalid payloads pass through to the existing 400/422 unchanged; declined/valued responses reuse the existing triage response shape (clarification Q3); superseded code retained and marked deprecated (cleanup of the stale `@deprecated` on `MessageExtractor`).

**Scale/Scope**: Single-tenant internal sales tool; low volume. The gate screens every public triage request (FR-001); `classification_results` already grows one row per inquiry and is retained for audit.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Independently Deployable Services — PASS.** The feature ships as an additive middleware + one migration inside inquiry-handler. Every outbound call is an existing HTTP consumer (auth-service token, work-scope-rag `/query`, Z.AI HTTPS). No shared imports, no cross-service DB access, no new service.
- **II. API-First — PASS.** The triage contract is amended to v2.1 in [contracts/inquiry-web.md](contracts/inquiry-web.md) (additive `context.scope_check`, decline marker). No new endpoint. `/health` retained.
- **III. Human-in-the-Loop — PASS.** `indeterminate` fails open (the inquiry is never fabricated or dropped, FR-007); declines are persisted on the classification log for human review (FR-008, SC-006/SC-007). Nothing is auto-resolved or hidden.
- **IV. Data Model Is the Source of Truth — PASS.** All scope-check state lives on the scoped `inquiry_handler` store's `classification_results` rows, owned by the inquiry-handler schema, written via the Eloquent model. Persisted in research R3 and [data-model.md](data-model.md).
- **V. Simplicity and Provisional Scope — PASS.** Smallest shape: one middleware plus a value object and one service; three additive columns; full reuse of `RagApiClient`, `AiCallingService`, `MessageExtractor`. No new service, no new table, no new UI.

**Post-design re-check (after Phase 1):** All gates still PASS with the design in [research.md](research.md) and [data-model.md](data-model.md) — the gate's only added surface is one middleware, one service, and a transient verdict object over existing HTTP consumers (I); contracts documented in v2.1 (II); fail-open indeterminate + persisted declines keep every inquiry human-visible (III); scope-check state lives in the scoped store's classification log (IV); additive columns with maximum reuse chosen (V). Complexity table left empty.

## Project Structure

### Documentation (this feature)

```text
specs/007-scope-gate-middleware/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output — decisions R1–R7
├── data-model.md        # Phase 1 output — classification_results amendments + Scope Verdict
├── quickstart.md        # Phase 1 output — end-to-end manual + automated validation
├── contracts/
│   └── inquiry-web.md      # Contract v2.1 — scope_check marker, decline shape (unchanged errors)
└── tasks.md             # Phase 2 output (/speckit.tasks - NOT created here)
```

### Source Code (repository root)

```text
inquiry-handler/
├── app/
│   ├── ScopeGate/                          # NEW namespace
│   │   ├── ScopeGateService.php            # NEW: retrieval -> fixed scope prompt -> ScopeVerdict
│   │   └── ScopeVerdict.php                # NEW: {outcome, reason, refusal} value object
│   ├── Http/
│   │   ├── Middleware/
│   │   │   └── ScopeGateMiddleware.php     # NEW: screens POST /inquiry/triage; pass-through on parse/validation failures; decline inserts record + returns refusal response
│   │   └── Controllers/
│   │       └── InquiryController.php       # MODIFIED: reads ScopeVerdict from request, passes to triage, includes scope_check in context
│   ├── Models/
│   │   └── ClassificationResult.php        # MODIFIED: add scope_check_outcome / scope_check_reason / refusal fill + casts
│   ├── Services/
│   │   ├── InquiryTriageService.php        # MODIFIED: accepts ?ScopeVerdict, writes scope_check columns, includes scope_check in context
│   │   ├── AiCallingService.php            # REUSED (complete() JSON mode); triage() stays @deprecated
│   │   └── RagApiClient.php                # REUSED (query())
│   └── Triage/
│       └── MessageExtractor.php            # MODIFIED: remove stale @deprecated tag (still the active extractor)
├── config/
│   └── scope_gate.php                      # NEW: enabled (SCOPE_GATE_ENABLED, default true), rag_top_k
├── database/
│   └── migrations/
│       └── 2026_09_14_000000_add_scope_check_to_classification_results_table.php  # NEW: additive 3 columns
├── routes/web.php                          # MODIFIED: gate middleware on POST /inquiry/triage only
├── .env / .env.example                     # MODIFIED: SCOPE_GATE_ENABLED, RAG_TOP_K
└── tests/
    ├── Feature/ScopeGateTest.php           # NEW: accept/decline/indeterminate/invalid-payload scenarios
    └── Unit/
        ├── ScopeCheckServiceTest.php       # NEW: retrieval/AI parse branches, prompt role separation
        └── ScopeVerdictTest.php            # NEW: value object invariants (refusal only on decline)
```

**Structure Decision**: Follows inquiry-handler's existing conventions. `App\ScopeGate` mirrors the `App\Scoring` / `App\Triage` namespace pattern; the middleware lives beside `VerifyUpstreamToken` in `App\Http\Middleware`; the migration is additive to the existing `classification_results` table (feature 006 pattern). No changes in dashboard, db/init, or work-scope-rag — the RAG service is only called, per the spec's assumptions. config/scope_gate.php follows the `config/scoring.php` precedent.

## Complexity Tracking

> No constitution violations are introduced; the table is left empty.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| (none) | | |