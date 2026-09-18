# Implementation Plan: AI Research Agent for Company & Person Enrichment

**Branch**: `010-ai-research-agent` | **Date**: 2026-09-18 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/010-ai-research-agent/spec.md`

## Summary

Replace the raw web-research findings in the `inquiry-handler` service with an
AI research agent. The agent runs a fixed pipeline on the same pre-classification
path as today's web-research step: **gather** candidate public results with the
existing research classes, **AI-filter** them down to the single named
company/person (discarding aggregator/competitor pages), **fetch** the bounded
content of the kept sources, and **summarize** a source-cited profile. The
agent's `{outcome, summary, sources}` replaces the current raw `company`/`person`
result lists in the response and the persisted `web_research` payload (spec
Clarifications Q1:C). It never fabricates, never blocks the inquiry, and fails
open (constitution III). No new external data source, no new service, no new
secrets, and no database migration are required.

## Technical Context

**Language/Version**: PHP 8.3+ (existing inquiry-handler, Laravel 13)

**Primary Dependencies**: Laravel HTTP client (Guzzle) for search + page fetch;
existing `App\Services\AiCallingService::complete()` (Z.AI GLM, JSON mode) for the
filter and summarize calls; existing `App\WebResearch\Providers\TavilyResearchProvider`
for candidate gathering. No new Composer packages.

**Storage**: PostgreSQL (`inquiry_handler`) — the existing `classification_results`
append-only log. The agent's result is stored in the existing `web_research` JSON
column (shape change only; **no migration**).

**Testing**: PHPUnit 12.5, SQLite `:memory:` (`RefreshDatabase`), `Http::fake()`
+ `mockery/mockery` (`$this->mock()`), `Tests\Feature\Support\UpstreamStubs`.

**Target Platform**: Docker Compose network; browser test console at `GET /`.

**Project Type**: Web-service (API-first, Laravel; pre-existing since feature 004/005).

**Performance Goals**: The whole enrichment step stays within a configured budget
(default ≤ ~25 s); the number of search calls, AI calls, and page fetches is
bounded. Over-budget runs degrade to `partial`/`indeterminate` instead of hanging
(SC-005).

**Constraints**: Privacy — only `company_name`, person name, and optional
`country_region` ever leave the service; email/phone never do (FR-001, SC-007).
Fail-open — the inquiry always completes (FR-007). Advisory only — the summary is
human-reviewed context, not an automated decision (constitution III). The
existing `web_research_outcome` value set (`accept|decline|indeterminate`) and
the provider `decline` short-circuit are preserved. No cross-service imports; all
outbound calls are HTTP.

**Scale/Scope**: Single service (`inquiry-handler`); ~3 new classes + 2 changed
classes + 1 config file; 3 contract docs; ~2 new test files and ~4 updated test
files.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

**Principle I — Independently Deployable Services**: No new service and no new
Dockerfile. The agent is additive inside the existing `inquiry-handler`, which
consumes its neighbors over HTTP only (Tavily/Z.AI/page fetches are outbound
HTTPS; no shared imports, no cross-service DB reads). PASS.

**Principle II — API-First, FastAPI by Default**: No new service is created; the
Laravel deviation is pre-existing and carried forward from features 004–009. The
public interface remains the documented `POST /inquiry/triage` JSON API. No
violation.

**Principle III — Human-in-the-Loop for Ambiguity**: Core to the design. The
agent never fabricates: unfindable → `not_found`, shared name → `ambiguous`, any
step failure → `indeterminate` (fail-open). No entity is guessed or merged, and
no inquiry is dropped or blocked (FR-006/FR-007, Stories 3–4). PASS.

**Principle IV — Data Model Is Source of Truth**: The agent's outcome, kept
sources, and summary are persisted on the existing `classification_results` row
(`web_research` JSON + `web_research_outcome`/`_reason`), readable through the
existing review API. No memory-only state. PASS.

**Principle V — Simplicity and Provisional Scope**: Smallest version — a fixed
pipeline (no autonomous loop, per Clarification Q3:C), reuse of the existing
provider/AI caller/HTTP client, no new data source, no new dependency, no schema
change. PASS.

No violations — no Complexity Tracking needed.

**Post-design re-check (after Phase 1)**: Re-evaluated against the generated
data model and contracts — still no violations. No new table/migration (existing
`web_research` JSON reused), no new dependency/data source/secret, fixed pipeline,
and every failure path resolves to an honest `not_found`/`ambiguous`/`indeterminate`
(fail-open). See research.md R9.

## Project Structure

### Documentation (this feature)

```text
specs/010-ai-research-agent/
├── plan.md                          # This file (/speckit.plan output)
├── research.md                      # Phase 0 output
├── data-model.md                    # Phase 1 output
├── quickstart.md                    # Phase 1 output
├── contracts/                       # Phase 1 output
│   ├── inquiry-web.md               # changed context.web_research shape
│   ├── classification-reporting.md  # changed persisted web_research shape
│   └── research-agent.md            # internal agent/PageFetcher contracts
├── checklists/
│   └── requirements.md
└── tasks.md                         # Phase 2 output (/speckit.tasks — NOT created here)
```

### Source Code (inquiry-handler service)

```text
inquiry-handler/
├── app/
│   ├── Models/
│   │   └── ClassificationResult.php              # docblock only — new findings shape
│   ├── Http/
│   │   └── Middleware/
│   │       ├── WebResearchMiddleware.php         # no functional change (passes verdict through)
│   │       └── ScopeGateMiddleware.php           # no functional change (passes research through)
│   ├── Providers/
│   │   └── AppServiceProvider.php                # (unchanged; agent auto-wires)
│   ├── Services/
│   │   └── InquiryTriageService.php              # no functional change (passes research through)
│   └── WebResearch/
│       ├── ResearchAgent.php                    # NEW — fixed pipeline orchestrator
│       ├── ResearchOutcome.php                  # NEW — completed/partial/not_found/ambiguous/indeterminate
│       ├── ResearchResult.php                   # NEW — value object (outcome + summary + sources)
│       ├── PageFetcher.php                      # NEW — bounded HTTP fetch + HTML→text
│       ├── WebResearchService.php               # CHANGED — delegates to ResearchAgent; new findings
│       ├── WebResearchProvider.php              # unchanged (gather boundary)
│       ├── WebResearchVerdict.php               # unchanged mechanically (payload shape documented)
│       └── Providers/
│           └── TavilyResearchProvider.php        # unchanged (candidate gathering)
├── config/
│   └── web_research.php                         # CHANGED — agent budget/fetch knobs
├── .env.example                                 # CHANGED — document new knobs
└── tests/
    ├── Feature/
    │   ├── WebResearchMiddlewareTest.php         # CHANGED — agent findings shape
    │   ├── ClassificationResultsAdminTest.php    # CHANGED — seeded web_research shape
    │   └── Support/UpstreamStubs.php             # CHANGED — agent fake helpers
    └── Unit/
        ├── ResearchAgentTest.php                 # NEW
        ├── PageFetcherTest.php                   # NEW
        ├── WebResearchServiceTest.php            # CHANGED
        └── TavilyResearchProviderTest.php        # unchanged (gather only)
```

**Structure Decision**: Single-service change inside the existing
`inquiry-handler/` Laravel app, concentrated in the `App\WebResearch` namespace.
No new services, directories at the repo level, or database tables.

## Complexity Tracking

No constitution violations — section left empty.
