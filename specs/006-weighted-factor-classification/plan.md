# Implementation Plan: Weighted Multi-Factor Inquiry Classification

**Branch**: `006-weighted-factor-classification` | **Date**: 2026-09-13 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/006-weighted-factor-classification/spec.md`

## Summary

The inquiry-handler's single-shot AI classification (decline/escalate/booking) is replaced by a **weighted multi-factor scoring engine**. Two new Eloquent models are added — a factor-settings record (factor name → weight, runtime-editable, single row) and a classification-result log (per-inquiry: factor scores, final score, classification, context). Factors plug in as **services behind a fixed `ScoreFactor` interface**, registered in code (dev-only); the engine reads weights, computes each factor's score (AI per-factor via the existing AI client), combines them into a final 0–100 score, and maps to one of **high / medium / low / disqualify** via config thresholds. Failed factors are dropped and remaining weights renormalized; empty catalog returns `low`. Every classification is persisted; the inbound triage response carries the new shape. A new **admin weights tab in the dashboard** reads/writes weights through a new inquiry-handler admin HTTP API (bearer-verified via auth-service), never touching the scoped DB directly. Superseded triage classes are kept and marked deprecated, never wired in.

## Technical Context

**Language/Version**: PHP 8.4 (runtime images `php:8.4-cli`), `php:^8.3` constraint — both inquiry-handler and dashboard (Laravel 13).

**Primary Dependencies**: `laravel/framework ^13.17` (Eloquent, `Http` facade), `pdo_pgsql` (new, Docker), PHPUnit 12 dev. Dashboard also uses Livewire 4 (native, no new deps needed — the weights tab follows the existing documents/edit form pattern).

**Storage**: PostgreSQL 16 (shared `db` service). New **dedicated databases `inquiry_handler`** (+ `inquiry_handler_test`), per the existing per-service DB isolation pattern (`rag`, `auth`, `rag_test`, `auth_test`). Only the inquiry-handler service is given the connection env. Tests run on **SQLite `:memory:`** (`RefreshDatabase`), never the scoped store.

**Testing**: PHPUnit 12. Inquiry-handler: unit tests for the scoring engine/factor/providers; feature tests for `/inquiry/triage` (new response shape) and `/admin/factor-settings`; existing legacy-class unit tests kept. Dashboard: feature tests for the factor-weights tab following the documents pattern (`UpstreamStubs` + `Http::fake` + `signIn`).

**Target Platform**: Linux, Docker Compose network (internal DNS `inquiry-handler:8003`, `db`, `auth-service`, etc.).

**Project Type**: HTTP web services — a stateless classification API (inquiry-handler) plus an admin console facet in the existing dashboard.

**Performance Goals**: No strict latency target; per-factor AI calls dominate and factor failure must not block classification. Factor execution is **sequential by default** (parallelism deferred — not a stated requirement). Target: a triage request completes in seconds, and completes even when an upstream factor source is down (FR-007).

**Constraints**: No shared in-process code across services; no direct cross-service database access (constitution I); scoped DB reachable only via HTTP (FR-010, SC-006); classification stays stateless on the request path (FR-013); superseded code retained and marked deprecated (FR-012).

**Scale/Scope**: Single-tenant internal sales tool; low volume (one admin, inbound inquiries). Classification results grow one row per inquiry and are retained for audit. Exactly one factor-settings row.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Independently Deployable Services — PASS.** New capability ships inside inquiry-handler (scoring engine, models, admin API). The dashboard only calls inquiry-handler over HTTP (`INQUIRY_HANDLER_URL`); it never touches the inquiry-handler DB. Each service keeps its own Dockerfile/Compose wiring.
- **II. API-First — PASS.** Documented contracts for `/inquiry/triage` (updated response) and the new `/admin/factor-settings` API (contracts/). `/health` retained on both services.
- **III. Human-in-the-Loop — PASS.** The four classifications are advisory; every inquiry remains visible to a human reviewer (FR-011). The former "escalate" disposition is removed per user decision; human review of *all* results is the operating model. Empty/failed states default to `low`, keeping inquiries surfaced rather than hidden.
- **IV. Data Model Is the Source of Truth — PASS.** Factor settings and classification results live in the dedicated Postgres store, owned by the inquiry-handler schema, read via the model layer by classification and by the admin API.
- **V. Simplicity and Provisional Scope — PASS.** Smallest version: factors start empty and are added one-by-one as code services; placeholder reply copy per classification; no role model or new UI framework added to the dashboard (reuses documents/edit form pattern); sequential factor execution.

**Post-design re-check (after Phase 1):** All gates still PASS with the design in [research.md](research.md) and [data-model.md](data-model.md). The dashboard↔inquiry-handler weights exchange is HTTP-only (I), contracts are documented (II), degraded/empty states default to `low` keeping every inquiry human-visible (III), the dedicated `inquiry_handler` store is owned by the inquiry-handler schema (IV), and the smallest workable shape was chosen (V). No complexity-tracking rows needed.

## Project Structure

### Documentation (this feature)

```text
specs/006-weighted-factor-classification/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   ├── inquiry-web.md        # Updated triage request/response contract
│   └── factor-settings-admin.md  # New admin weights API contract
└── tasks.md             # Phase 2 output (/speckit.tasks - NOT created here)
```

### Source Code (repository root)

```text
inquiry-handler/
├── app/
│   ├── Enums/
│   │   └── Classification.php            # NEW: high|medium|low|disqualify (replaces use of Disposition in the flow)
│   ├── Models/
│   │   ├── Factor.php                    # NEW: single factor-settings row, weights JSON
│   │   └── ClassificationResult.php      # NEW: per-inquiry classification log
│   ├── Scoring/                          # NEW namespace
│   │   ├── ScoreFactor.php               # NEW: factor service interface
│   │   ├── FactorVerdict.php             # NEW: {score 0-100, reasoning, meta} value object
│   │   ├── FactorScore.php               # NEW: {factor, weight, score, weighted, reasoning}
│   │   ├── FactorScorer.php              # NEW: governs one factor (weight + calculator + clamp)
│   │   ├── FactorRegistry.php            # NEW: name -> ScoreFactor map (dev-only registration)
│   │   ├── ClassificationOutcome.php     # NEW: {classification, score, factorScores, reasoning}
│   │   └── ScoringEngine.php             # NEW: main aggregate -> final score -> classification
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── InquiryController.php     # MODIFIED: returns new response shape
│   │   │   └── AdminFactorSettingsController.php  # NEW: GET|PUT /admin/factor-settings
│   │   └── Middleware/
│   │       └── VerifyUpstreamToken.php   # NEW: bearer -> auth-service /auth/verify gate for admin API
│   └── Services/
│       ├── InquiryTriageService.php      # REWRITTEN: RAG -> ScoringEngine -> persist -> reply
│       └── AiCallingService.php          # MODIFIED: adds generic complete(); triage() kept @deprecated
├── config/
│   └── scoring.php                       # NEW: thresholds, default weight, replies, score range
├── database/
│   ├── migrations/                       # NEW: factor_settings, classification_results (+test DB)
│   └── seeders/                          # NEW: factor settings seed (one row) - or inline in migration
├── docker-entrypoint.sh                  # MODIFIED: php artisan migrate --force
├── Dockerfile                            # MODIFIED: add pdo_pgsql (+libpq-dev)
├── routes/web.php                        # MODIFIED: admin routes (excluded from CSRF, like /inquiry/triage)
├── .env / .env.example                   # MODIFIED: DB_* + scoring/settings env
└── tests/                                # MODIFIED: scoring unit tests, feature tests, stubs

dashboard/
├── app/
│   ├── Services/
│   │   └── InquiryHandlerApiClient.php       # NEW: getFactorSettings/updateFactorWeights
│   └── Http/Controllers/
│       └── FactorWeightsController.php       # NEW: index() + update() (auth.upstream)
├── resources/views/
│   ├── factor-weights/index.blade.php        # NEW: weights editor form
│   └── layouts/app.blade.php                 # MODIFIED: nav "Factor weights" tab
├── routes/web.php                            # MODIFIED: /factor-weights GET|PUT inside auth.upstream
├── config/services.php                       # MODIFIED: inquiry_handler_api_url
├── .env / .env.example                       # MODIFIED: INQUIRY_HANDLER_URL
└── tests/Feature/FactorWeightsTest.php       # NEW: redirect/render/save/validation/401

db/
└── init.sql                                  # MODIFIED: CREATE DATABASE inquiry_handler, inquiry_handler_test

docker-compose.yml                            # MODIFIED: inquiry-handler depends_on db + DB_*; dashboard INQUIRY_HANDLER_URL
```

**Structure Decision**: Follows each service's existing convention. The scoring subsystem is a new `App\Scoring` namespace in inquiry-handler (mirrors the current `App\Triage` layout). The dashboard reuses the documents/edit + Livewire-less plain-controller form pattern (Leaner: follow `EditDocument` shape but as a standard controller+Blade form using `x-upstream-error`). No frontend build changes. DB bootstrap changes are additive to the existing `db/init.sql` pattern.

## Complexity Tracking

> No constitution violations are introduced; the table is left empty.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| (none) | | |