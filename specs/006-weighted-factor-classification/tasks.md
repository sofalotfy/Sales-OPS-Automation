---

description: "Task list for weighted multi-factor inquiry classification"
---

# Tasks: Weighted Multi-Factor Inquiry Classification

**Input**: Design documents from `/specs/006-weighted-factor-classification/`

**Prerequisites**: plan.md (required) · spec.md (required for user stories) · research.md · data-model.md · contracts/inquiry-web.md · contracts/factor-settings-admin.md · quickstart.md

**Tests**: Included as tasks. This project's acceptance requires the existing suites to stay green (`composer test` in both inquiry-handler and dashboard) plus unit coverage of the new scoring engine, per quickstart.md.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story. The P1 stories (US1/US2/US3) are technically coupled; see "Dependencies & Execution Order" for the concrete build order (US3 → US2 → US1) behind the priority-ordered phases below.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US6)
- Include exact file paths in descriptions

## Path Conventions

- Repo is a multi-service monorepo; each service has its own tree. Scope of each task is explicit per path:
  - `inquiry-handler/...` (Laravel 13 classification service)
  - `dashboard/...` (Laravel 13 admin console)
  - `db/init.sql`, `docker-compose.yml` (shared infra)
- Tests use SQLite `:memory:` + `RefreshDatabase` on inquiry-handler; the dashboard keeps its existing `Http::fake`/`signIn` pattern.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization and provisioning for the new persistence + scoring subsystems.

- [X] T001 Create new directories per plan.md structure: `inquiry-handler/app/Enums`, `inquiry-handler/app/Models`, `inquiry-handler/app/Scoring`, `inquiry-handler/database/migrations`, `inquiry-handler/database/seeders`, `dashboard/resources/views/factor-weights`
- [X] T002 Add `config/scoring.php` to inquiry-handler per research R11: `score_min` 0, `score_max` 100, `default_factor_weight` 1.0, `thresholds` (`high` 75, `medium` 55, `low` 30), `empty_catalog_classification` `low`, and `replies` placeholder strings per classification (high uses `services.booking_url` with the existing valid-URL guard; medium/low/disqualify get static placeholders)
- [X] T003 [P] Update `db/init.sql` to `CREATE DATABASE inquiry_handler OWNER rag;` and `CREATE DATABASE inquiry_handler_test OWNER rag;` (research R8; mirrors the existing rag/auth `_test` pattern)
- [X] T004 Update `docker-compose.yml`: inquiry-handler gains `depends_on: db` (condition `service_healthy`) and `DB_HOST=db`/`DB_PORT=5432`/`DB_DATABASE=inquiry_handler`/`DB_USERNAME=rag`/`DB_PASSWORD=rag`; dashboard service gains `INQUIRY_HANDLER_URL=http://inquiry-handler:8003`
- [X] T005 Update `inquiry-handler/.env.example` and `inquiry-handler/.env` with `DB_CONNECTION=pgsql`, `DB_HOST=db`, `DB_PORT=5432`, `DB_DATABASE=inquiry_handler`, `DB_USERNAME=rag`, `DB_PASSWORD=rag`
- [X] T006 Update `inquiry-handler/Dockerfile` runtime stage: install `libpq-dev` and enable `pdo_pgsql` in `docker-php-ext-install` (research R8)
- [X] T007 Update `inquiry-handler/docker-entrypoint.sh` to run `php artisan migrate --force` before the config/route/view caches
- [X] T008 [P] Add `'inquiry_handler_api_url' => env('INQUIRY_HANDLER_URL', 'http://inquiry-handler:8003')` to `dashboard/config/services.php` and `INQUIRY_HANDLER_URL` to `dashboard/.env.example` and `dashboard/.env`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core enablers needed by every user story.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T009 Create `App\Enums\Classification` in `inquiry-handler/app/Enums/Classification.php` as a backed string enum with cases `high`, `medium`, `low`, `disqualify` (FR-001)
- [X] T010 Configure the inquiry-handler test harness for SQLite `:memory:`: set `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:` in `inquiry-handler/phpunit.xml` env, and make DB-touching feature/unit tests use `RefreshDatabase` (assumption: throwaway store; never the scoped store)

**Checkpoint**: Foundation ready — user story implementation can begin (build order US3 → US2 → US1).

---

## Phase 3: User Story 1 - Every Inquiry Gets a Weighted Score and a Classification (Priority: P1) 🎯 MVP

**Goal**: The triage endpoint returns exactly one of high/medium/low/disqualify plus a numeric final score and a per-factor score/weight breakdown, powered by a scoring engine.

**Independent Test**: `POST /inquiry/triage` returns `classification`, `score`, `factor_scores`, `dropped_factors`, `reply`, `reasoning`, `context` (contract: contracts/inquiry-web.md). With the catalog empty this returns `low`/0.00.

> Depends on US3 models (T027–T031, especially `Factor`/`ClassificationResult`) and the empty `FactorRegistry` from US2 (T022–T023). US1 is functional with an empty catalog; see Execution Order.

### Implementation for User Story 1

- [X] T011 [P] [US1] Create `FactorScore` value object in `inquiry-handler/app/Scoring/FactorScore.php` (`factor`, `score`, `weight`, `weighted` (score×weight), `reasoning`)
- [X] T012 [P] [US1] Create `ClassificationOutcome` value object in `inquiry-handler/app/Scoring/ClassificationOutcome.php` (`classification: Classification`, `score: float`, `factorScores: FactorScore[]`, `reasoning: string`, `droppedFactors: array`)
- [X] T013 [US1] Implement `FactorScorer` in `inquiry-handler/app/Scoring/FactorScorer.php`: resolves weight via `Factor::instance()->weightFor()` falling back to `config('scoring.default_factor_weight')`, invokes the factor's `ScoreFactor`, clamps the score to [0,100], and returns a `FactorScore`
- [X] T014 [US1] Implement `ScoringEngine` in `inquiry-handler/app/Scoring/ScoringEngine.php`: iterates `FactorRegistry`, calls `FactorScorer` per factor, computes the weighted mean `Σ(score·weight)/Σ(weight)`, maps the final score through `config('scoring.thresholds')` (inclusive lower bounds) into a `Classification`, and returns a `ClassificationOutcome` (empty catalog → score 0.00/`low`)
- [X] T015 [US1] Rewrite `InquiryTriageService::triage()` in `inquiry-handler/app/Services/InquiryTriageService.php`: RAG query (existing failure semantics) → build context → `ScoringEngine->classify()` → persist `ClassificationResult` (via `ClassificationResult::create`, US3 T030) → select reply from `config('scoring.replies')` → return the new response array (classification/score/factor_scores/dropped_factors/reply/reasoning/context)
- [X] T016 [US1] Implement reply selection inside `InquiryTriageService`: `high` embeds `services.booking_url` only when it is a valid URL (else generic placeholder); medium/low/disqualify use static placeholders from `config('scoring.replies')`
- [X] T017 [US1] Update the test console `inquiry-handler/resources/views/inquiry/index.blade.php` to render `classification`, the numeric `score`, `factor_scores`, and `dropped_factors` instead of the old `disposition`
- [X] T018 [US1] Update inquiry-handler feature tests for the new response shape: `inquiry-handler/tests/Feature/InquiryTriageTest.php`, `inquiry-handler/tests/Feature/FailurePathsTest.php`, and `inquiry-handler/tests/Feature/Support/UpstreamStubs.php` (empty-catalog runs assert `classification === 'low'`; drop old `disposition`/Z.AI-disposition assertions; keep RAG/auth stubs; keep Z.AI stubs available for future factor tests)
- [X] T019 [US1] Add scoring unit tests in `inquiry-handler/tests/Unit/ScoringEngineTest.php`: weighted-mean math, inclusive threshold boundaries (exactly 75→high, 55→medium, 30→low, 29.99→disqualify), clamping, and empty catalog → `low`/0.00

**Checkpoint**: At this point, User Story 1 is functional and independently testable (returns `low`/0.00 with the empty catalog; full behavior once factors are added in US2).

---

## Phase 4: User Story 2 - Factors Are Defined as Pluggable Services Behind a Fixed Interface (Priority: P1)

**Goal**: A factor is a service behind a fixed interface; the set is registered in code only (dev-only), enabling "add a factor by defining a service + registering it" (SC-003).

**Independent Test**: A developer adds one factor service + registers it; its score appears in the triage `factor_scores` breakdown with no change to the engine (unit test with a stub factor).

### Implementation for User Story 2

- [X] T020 [US2] Create the `ScoreFactor` interface in `inquiry-handler/app/Scoring/ScoreFactor.php`: `name(): string` and `score(array $inquiry, array $context): FactorVerdict` (FR-002)
- [X] T021 [P] [US2] Create the `FactorVerdict` value object in `inquiry-handler/app/Scoring/FactorVerdict.php` (`score` int 0–100, `reasoning` string, `meta` array)
- [X] T022 [US2] Implement `FactorRegistry` in `inquiry-handler/app/Scoring/FactorRegistry.php` (name→`ScoreFactor` map; `add()`/`all()`/`has()`), initialized empty
- [X] T023 [US2] Register the `FactorRegistry` binding (empty catalog) in `inquiry-handler/app/Providers/AppServiceProvider.php`; retain the existing deprecated `PromptBuilder` binding untouched (FR-012)
- [X] T024 [US2] Add generic `complete(string $system, string $user): ?array` to `inquiry-handler/app/Services/AiCallingService.php` (reuses the existing Z.AI HTTP / JSON-mode / 422-fallback mechanics; returns decoded JSON or `null` on any failure); leave `triage()` intact (deprecated in US5)
- [X] T025 [US2] Add a unit test proving pluggability (SC-003) in `inquiry-handler/tests/Unit/FactorRegistryTest.php` or `ScoringEngineTest.php`: register a stub `ScoreFactor`, run the engine, assert its entry appears in `factor_scores` and its weight is applied
- [X] T026 [US2] Document "how to add a factor" in `inquiry-handler/README.md` (define a `ScoreFactor` service that may use `AiCallingService::complete()`, then register it in `AppServiceProvider`)

**Checkpoint**: Factors are now pluggable; user stories 1 and 3 can be demonstrated together.

---

## Phase 5: User Story 3 - Two Models Persist the Weights and Every Classification (Priority: P1)

**Goal**: The two requested models — a single factor-settings row (factor name → weight, runtime-editable) and per-inquiry classification results (all factor scores, final result, context) — backed by the scoped `inquiry_handler` store.

**Independent Test**: Models read/write on SQLite `:memory:` via migrations; a stored weight change is picked up by `Factor::weightFor()`; `ClassificationResult` records every factor score + final result.

### Implementation for User Story 3

- [X] T027 [P] [US3] Create migration for `factor_settings` in `inquiry-handler/database/migrations/` (portable to Postgres + SQLite): `id`, `weights` json default `{}`, timestamps; insert the single seed row with `weights = {}` (data-model.md)
- [X] T028 [US3] Create `App\Models\Factor` in `inquiry-handler/app/Models/Factor.php`: casts `weights` to array; helpers `instance()` (fetch-or-create the singleton), `weights()`, `weightFor(string $name): ?float`
- [X] T029 [P] [US3] Create migration for `classification_results` in `inquiry-handler/database/migrations/` (portable): `inquiry_message` text, `name`/`email` nullable, `retrieved_context` json, `factor_scores` json, `dropped_factors` json, `final_score` numeric(5,2), `classification` string(20), `reasoning` text nullable, timestamps (data-model.md)
- [X] T030 [US3] Create `App\Models\ClassificationResult` in `inquiry-handler/app/Models/ClassificationResult.php`: cast `retrieved_context`/`factor_scores`/`dropped_factors` to array, `classification` to the `Classification` enum; no update/delete surface (append-only audit log, FR-009)
- [X] T031 [US3] Add model unit tests in `inquiry-handler/tests/Unit/FactorTest.php` and `inquiry-handler/tests/Unit/ClassificationResultTest.php` (RefreshDatabase, SQLite): singleton row creation, `weightFor` default behavior, and create/read round-trip of a full classification record

**Checkpoint**: Both models persist correctly; runtime weight reads flow into the engine (US1).

---

## Phase 6: User Story 4 - Classification Survives Missing Data and Failures (Priority: P2)

**Goal**: Classification always completes: failed factors are dropped + remaining weights renormalized, empty catalog → `low`, unreadable weights store → defaults, and a failed classification-log write never breaks the response (FR-007/FR-008, SC-005).

**Independent Test**: Forcing a factor failure, an empty catalog, and a log-write failure each still yields a valid 200 classification.

### Implementation for User Story 4

- [X] T032 [US4] In `ScoringEngine` (`inquiry-handler/app/Scoring/ScoringEngine.php`): wrap each factor run so a thrown exception drops that factor, records it in `droppedFactors` with the reason, and renormalizes the remaining weights (research R3b / FR-007)
- [X] T033 [US4] In `ScoringEngine`: short-circuit the empty-catalog case to `final_score = 0.00` and `classification = low` (research R3a / FR-008, clarification Q2)
- [X] T034 [US4] In `FactorScorer` (`inquiry-handler/app/Scoring/FactorScorer.php`): catch store-read failure around `Factor::instance()` and fall back to `config('scoring.default_factor_weight')`, logging the error (research R3c)
- [X] T035 [US4] In `InquiryTriageService` (`inquiry-handler/app/Services/InquiryTriageService.php`): wrap `ClassificationResult::create` in try/catch, `Log::error` on failure, and always return the 200 classification response (research R3d)
- [X] T036 [US4] Confirm/clamp out-of-range factor scores to [0,100] in `FactorScorer` (already from T013; add explicit on-by-degenerate-input test)
- [X] T037 [US4] Extend `inquiry-handler/tests/Unit/ScoringEngineTest.php` and `inquiry-handler/tests/Feature/FailurePathsTest.php`: failing factor is dropped with renormalization and appears in `dropped_factors`; empty catalog returns `low`; classification-log write failure still returns 200; RAG 503 → degraded 200 `low`; unknown/duplicate weight keys ignored

**Checkpoint**: The failure matrix is covered before the admin surface ships.

---

## Phase 7: User Story 6 - Admin Adjusts Factor Weights From the Dashboard (Priority: P2)

**Goal**: New inquiry-handler admin API (`GET|PUT /admin/factor-settings`, bearer-verified via auth-service) plus a "Factor weights" tab in the dashboard that lists and edits weights over HTTP only (FR-005, FR-010, SC-004/SC-006).

**Independent Test**: From the dashboard tab, change a weight; the next classification reflects it. The tab is gated by `auth.upstream`; the API is gated by `VerifyUpstreamToken`. Unknown factors are rejected 422 (FR-004).

> Contract: contracts/factor-settings-admin.md.

### Inquiry-handler (admin API)

- [X] T038 [US6] Add `verify(string $token): Response` to `inquiry-handler/app/Services/AuthApiClient.php` (`GET /auth/verify` with bearer token; returns the `Response`, no throwing)
- [X] T039 [US6] Create `App\Http\Middleware\VerifyUpstreamToken` in `inquiry-handler/app/Http/Middleware/VerifyUpstreamToken.php`: resolve the bearer token from the `Authorization` header, call `AuthApiClient::verify()`, allow on 200/201, otherwise `401` JSON `{"detail":"Not authenticated."}` (research R6)
- [X] T040 [US6] Create `App\Services\FactorSettingsService` in `inquiry-handler/app/Services/FactorSettingsService.php`: `effectiveWeights()` (one entry per registered factor: stored `weightFor` else default + `source` `stored`|`default`), `storedWeights()`, and `updateWeights(array $weights)` persisting only registered factor names
- [X] T041 [US6] Create `App\Http\Controllers\AdminFactorSettingsController` in `inquiry-handler/app/Http/Controllers/AdminFactorSettingsController.php`: `index()` → `{factors, stored}`; `update(Request)` → validate then `FactorSettingsService::updateWeights()`, return the updated `{factors}` (contracts/factor-settings-admin.md)
- [X] T042 [US6] Route and wire in `inquiry-handler/routes/web.php` (+ `bootstrap/app.php` CSRF exemption for `admin/*`): `GET /admin/factor-settings` and `PUT /admin/factor-settings`, both behind `VerifyUpstreamToken`; add validation: weights object required, names must exist in the registry (unknown → 422 "Unknown factor: <name>"), each weight a finite number ≥ 0 (else 422) — FR-004
- [X] T043 [US6] Add feature tests `inquiry-handler/tests/Feature/FactorSettingsAdminTest.php` + extend `tests/Feature/Support/UpstreamStubs.php` (auth-verify helpers): 401 without/invalid token, GET returns empty `factors` on an empty catalog, GET reflects stored weights, PUT ok, PUT unknown factor → 422, PUT non-numeric weight → 422

### Dashboard (admin tab)

- [X] T044 [US6] Create `app/Services/InquiryHandlerApiClient.php` in `dashboard/app/Services/InquiryHandlerApiClient.php` (mirrors `RagApiClient` convention): `baseUrl()` from `config('services.inquiry_handler_api_url')`, `getFactorSettings(string $token): Response` → `GET /admin/factor-settings`, `updateFactorWeights(string $token, array $weights): Response` → `PUT /admin/factor-settings`; `acceptJson()->withToken()->timeout(10)`, never throws
- [X] T045 [US6] Add routes in `dashboard/routes/web.php` inside the `auth.upstream` group: `GET /factor-weights` → `factor-weights.index`, `PUT /factor-weights` → `factor-weights.update`
- [X] T046 [US6] Create `App\Http\Controllers\FactorWeightsController` in `dashboard/app/Http/Controllers/FactorWeightsController.php`: `index()` loads settings via `InquiryHandlerApiClient::getFactorSettings(UpstreamSession::token())`, redirects to `login.show` on 401, passes `$factors`/`$stored`/`$error` to the view; `update()` validates weights (numeric ≥ 0) then calls `updateFactorWeights()`, redirect back with success/error (existing `back()->withErrors()` pattern)
- [X] T047 [US6] Create `dashboard/resources/views/factor-weights/index.blade.php` (`@extends('layouts.app')`, `x-upstream-error`, card form with one numeric input per factor showing name + current weight, `factor-weights.update` save button — Tailwind conventions from the documents pages)
- [X] T048 [US6] Add a "Factor weights" nav link in `dashboard/resources/views/layouts/app.blade.php` next to "Documents"
- [X] T049 [US6] Add dashboard feature tests `dashboard/tests/Feature/FactorWeightsTest.php` + extend `dashboard/tests/Feature/Support/UpstreamStubs.php` (`inquiryUrl(suffix)` helper + weight fixtures): unauthenticated → redirect `login.show`, render lists factors, save success, validation error, upstream 401 → re-login

**Checkpoint**: Admins can view/edit weights; SC-004 and SC-006 are demonstrable.

---

## Phase 8: User Story 5 - Existing Classification Code Is Kept, Tagged, and Not Wired In (Priority: P3)

**Goal**: Superseded triage classes remain present, marked `@deprecated`, and are never invoked by the new flow (FR-012, SC-007).

**Independent Test**: Grep for the legacy classes → all carry deprecation markers; the new flow never instantiates them.

### Implementation for User Story 5

- [X] T050 [P] [US5] Add `@deprecated` docblocks ("superseded by the scoring engine (feature 006); retained for reference, not wired into the flow") to `inquiry-handler/app/Triage/Disposition.php`, `inquiry-handler/app/Triage/TriageResult.php`, `inquiry-handler/app/Triage/Dispatcher.php`, `inquiry-handler/app/Triage/PromptBuilder.php`, `inquiry-handler/app/Triage/Handlers/TriageHandler.php`, `inquiry-handler/app/Triage/Handlers/DeclineHandler.php`, `inquiry-handler/app/Triage/Handlers/EscalateHandler.php`, and `inquiry-handler/app/Triage/Handlers/BookingHandler.php`
- [X] T051 [US5] Add `@deprecated` to `AiCallingService::triage()` in `inquiry-handler/app/Services/AiCallingService.php` (the generic `complete()` from T024 is the live path)
- [X] T052 [US5] Verify the legacy unit tests still pass unchanged: `inquiry-handler/tests/Unit/BookingHandlerTest.php`, `DeclineHandlerTest.php`, `EscalateHandlerTest.php`, `DispatcherTest.php`, `PromptBuilderTest.php` (no edits expected)

**Checkpoint**: No deletions; nothing in `App\Scoring` or the rewritten triage flow references the legacy classes.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Full-suite green, scoped-store audit, and end-to-end validation per quickstart.md.

- [X] T053 Run the full inquiry-handler suite: `cd inquiry-handler && composer test` — all feature/unit tests green (incl. legacy tests and new scoring/admin tests)
- [X] T054 Run the full dashboard suite: `cd dashboard && composer test` — all tests green (incl. `FactorWeightsTest`)
- [X] T055 [P] SC-006 audit: `rg -n "inquiry_handler"` across the repo — only `inquiry-handler` (and `db/init.sql`) reference the database name; no other service env/config/compose references it
- [X] T056 [P] SC-007 audit: confirm every legacy triage class carries a deprecation marker and the new flow (`App\Scoring`, rewritten `InquiryTriageService`) never instantiates `Disposition`/`Dispatcher`/`PromptBuilder`/handlers
- [X] T057 Run the quickstart.md manual validation scenarios end-to-end (empty-catalog triage `low`, admin GET/PUT, FR-004 unknown-factor rejection, dashboard weight edit reflected in the next triage)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies, can start immediately.
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories.
- **User Stories (Phase 3+)**: All depend on Foundational. The P1 trio (US1/US2/US3) shares the scoring subsystem and must be built together; see build order below.
- **Polish (Phase 9)**: Depends on all desired stories being implemented.

### User Story Dependencies (build order)

The phases are listed in priority order (P1 → P2 → P3); the concrete build order for the coupled P1 trio is:

1. **US3 (T027–T031)** — the two models; everything else reads/writes them.
2. **US2 (T020–T026)** — `ScoreFactor` + `FactorRegistry` + `AiCallingService::complete()`; the engine consumes the registry.
3. **US1 (T011–T019)** — engine + triage response; functional with an empty catalog (returns `low`/0.00), fully demonstrable once ≥1 factor exists.
4. **US4 (T032–T037)** — failure hardening on top of the engine.
5. **US6 (T038–T049)** — admin API + dashboard tab (depends on US2 registry for FR-004 name validation).
6. **US5 (T050–T052)** — deprecation tagging; can be done any time after the new flow exists.
7. **Polish (T053–T057)**.

### Within Each User Story

- Models before services, services before endpoints
- Core implementation before integration
- Story is complete before moving to the next priority

### Parallel Opportunities

- All `[P]` tasks run in parallel (disjoint files within their phase).
- T023 (registry registration) can run while T024 (AI complete) is in progress.
- US3's two migrations/models are fully parallel (T027/T028 vs T029/T030).
- Inquiry-handler admin API (T038–T043) and the dashboard tab (T044–T049) are implementable in parallel.
- US5 deprecation markers (T050–T052) are independent of US4/US6 work.

### Parallel Example: US3 models

```text
Task: "Create migration for factor_settings + Factor model (T027/T028)"
Task: "Create migration for classification_results + ClassificationResult model (T029/T030)"
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational).
2. Build US3 (models) then US2 (factor interface/registry) as the enablers.
3. Build US1 (engine + triage response). With the catalog empty it returns `low`/0.00 — still a valid, shippable API.
4. **STOP and VALIDATE**: run `composer test` (both services), verify the triage response shape via quickstart.
5. Deploy/demo if ready.

### Incremental Delivery

1. Setup + Foundational → foundation ready.
2. US3 + US2 + US1 → core classification live (MVP, empty catalog + pluggable factors).
3. US4 → failure-hardened classification.
4. US6 → admin can tune weights from the dashboard.
5. US5 + Polish → deprecation hygiene, audits, full suite green.

### Parallel Team Strategy

- Team completes Setup + Foundational together.
- Person A: US3 + US1 (models + engine/response).
- Person B: US2 (factor interface/registry/AI-complete) in parallel.
- Then Person C: US4, Person D: US6 (inquiry-handler API) while Person E does the dashboard tab.
- US5 and audits anytime after the new flow exists.

---

## Notes

- [P] tasks = different files, no dependencies.
- [Story] label maps the task to a user story for traceability.
- Tests are written or updated alongside their implementation; the feature ships only with both suites green.
- Legacy triage classes are never deleted and never called by the new flow (FR-012).
- Commit after each task or logical group; stop at any checkpoint to validate independently.