---

description: "Task list for scope gate middleware"
---

# Tasks: Scope Gate Middleware

**Input**: Design documents from `/specs/007-scope-gate-middleware/`

**Prerequisites**: plan.md (required) · spec.md (required for user stories) · research.md (decisions R1–R8) · data-model.md (classification_results amendments) · contracts/inquiry-web.md (v2.1) · quickstart.md

**Tests**: Included as tasks. This project's spec mandates "User Scenarios & Testing," and quickstart.md requires automated validation — `composer test` in inquiry-handler (existing suite stays green) plus the new scope-gate feature tests.

**Organization**: Tasks are grouped by user story (US1–US4). All four stories touch the shared middleware/verdict subsystem, so they ship in the documented build order (US1 → US2 → US3 → US4); each story is still independently testable at its checkpoint.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US4)
- Include exact file paths in descriptions

## Path Conventions

- Repo is a multi-service monorepo; this feature touches **only** `inquiry-handler/...` (Laravel 13). No dashboard, db/init, docker-compose, or work-scope-rag changes (plan.md — the gate is a pure HTTP consumer of existing services).
- Tests use SQLite `:memory:` + `RefreshDatabase` with `Http::fake()` + `tests/Feature/Support/UpstreamStubs.php` (feature-006 pattern); the scoped Postgres store is never touched by tests.
- Existing keys reused (do not re-add): `RAG_TOP_K` already lives in `config/app.php` as `app.rag_top_k` and in `.env`/`.env.example`; AI config already lives in `services.zai.*`; scope statement in `services.company_scope`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Gate configuration knobs.

- [X] T001 Create `config/scope_gate.php` in `inquiry-handler/config/scope_gate.php` per plan/research R6: `enabled` from env `SCOPE_GATE_ENABLED` (default `true`, kill switch) and `rag_top_k` from env `RAG_TOP_K` (default 5, same env the classification flow already reads via `config('app.rag_top_k')`)
- [X] T002 [P] Add `SCOPE_GATE_ENABLED=true` to `inquiry-handler/.env` and `inquiry-handler/.env.example` (`RAG_TOP_K` already present in both — do not duplicate)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The persistence, verdict object, and scope-check service every user story depends on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete. ScopeGateMiddleware (US1) cannot exist without T006.

- [X] T003 Create the additive migration `inquiry-handler/database/migrations/2026_09_14_000000_add_scope_check_to_classification_results_table.php`: add `scope_check_outcome` string(20) nullable, `scope_check_reason` text nullable, `refusal` text nullable; enforce the outcome value set (`accept`/`decline`/`indeterminate`) via a check constraint; must be portable to both PostgreSQL 16 and the SQLite `:memory:` test store (data-model.md)
- [X] T004 Amend `App\Models\ClassificationResult` in `inquiry-handler/app/Models/ClassificationResult.php`: add `scope_check_outcome`, `scope_check_reason`, `refusal` to `$fillable`; keep the append-only posture (no update/delete surface)
- [X] T005 [P] Create the `App\ScopeGate\ScopeVerdict` value object in `inquiry-handler/app/ScopeGate/ScopeVerdict.php`: immutable `outcome` (`accept`|`decline`|`indeterminate`), `reason: string`, `refusal: ?string`; invariants — `refusal` is present only on `decline`, `reason` is always non-empty (research R2, data-model.md)
- [X] T006 Implement `App\ScopeGate\ScopeCheckService` in `inquiry-handler/app/ScopeGate/ScopeCheckService.php` (research R2/R4/R5): retrieve via `RagApiClient::query($message, (int) config('scope_gate.rag_top_k'))` — `ConnectionException`/`failed()`/missing `results` → `indeterminate`, zero `result_count` → `indeterminate`; else call `AiCallingService::complete()` (JSON mode) with a **fixed** system prompt containing only `services.company_scope` + the binary scope rule, and the message + retrieved documents placed strictly as `[USER INQUIRY]`/`[RETRIEVED DOCUMENTS]` user-role data blocks (FR-003/FR-009); `complete()` returning `null`/unparseable/missing `in_scope` → `indeterminate`; `in_scope: true` → `accept`; `in_scope: false` with non-empty `reason` → `decline` (that reason is the refusal copy)
- [X] T007 Remove the stale `@deprecated` docblock on `App\Triage\MessageExtractor` in `inquiry-handler/app/Triage/MessageExtractor.php` — it is still the active extraction path used by the controller wiring (plan.md); leave its behavior unchanged

**Checkpoint**: Foundation ready — verdict decision rules, persistence columns, and the extraction helper all exist.

---

## Phase 3: User Story 1 - In-Scope Inquiries Pass Straight Through (Priority: P1) 🎯 MVP

**Goal**: The gate screens an inquiry, returns `accept`, and the request proceeds to the full classification flow unchanged (FR-001/FR-005, SC-002).

**Independent Test**: `POST /inquiry/triage` with an in-scope inquiry returns the exact pre-gate response — same `classification`/`score`/`factor_scores`/`reply` (incl. booking link when qualified) — plus `context.scope_check.outcome = "accept"`; the stored row carries `scope_check_outcome = "accept"`.

### Implementation for User Story 1

- [X] T008 [US1] Create `App\Http\Middleware\ScopeGateMiddleware` in `inquiry-handler/app/Http/Middleware/ScopeGateMiddleware.php`: `json_decode` the body — non-object payload → `$next($request)` (Edge Case: the controller's 400 must fire unchanged); `config('scope_gate.enabled') === false` → `$next($request)` (kill switch bypass, no screening); extract via `MessageExtractor` — `MessageValidationException` → `$next($request)` (the controller's 422 must fire unchanged); run `ScopeCheckService` → `ScopeVerdict`, stash it on the request attributes (`scope_check_verdict`); `accept` → `$next($request)`; `indeterminate` → `$next($request)` (fail-open, formalized in US3 T016); `decline` → refuse (branch completed in US2 T013)
- [X] T009 [US1] Register and route the middleware in `inquiry-handler/bootstrap/app.php` (alias `scope.gate`) and `inquiry-handler/routes/web.php`: apply it to `POST /inquiry/triage` **only** (public path; admin/operator routes untouched — spec Assumption)
- [X] T010 [US1] Modify `InquiryController::triage` in `inquiry-handler/app/Http/Controllers/InquiryController.php`: read the `scope_check_verdict` (ScopeVerdict) from `$request->attributes` and pass it to `InquiryTriageService::triage()` (research R6)
- [X] T011 [US1] Modify `InquiryTriageService` in `inquiry-handler/app/Services/InquiryTriageService.php`: accept an optional `?ScopeVerdict`; for `accept`/`indeterminate` persist `scope_check_outcome`/`scope_check_reason` (`refusal` null) on the `ClassificationResult` row (FR-012); include `scope_check: {outcome, reason}` in the response `context` (contracts/inquiry-web.md v2.1); accept-path behavior otherwise unchanged (FR-010)
- [X] T012 [US1] Add accept-path feature tests to `inquiry-handler/tests/Feature/ScopeGateTest.php` (extend `inquiry-handler/tests/Feature/Support/UpstreamStubs.php` as needed): in-scope inquiry → 200 with the pre-gate shape (locations: `classification`, `score`, `factor_scores`, `dropped_factors`, `reply`, `reasoning`, `context`; booking-link-bearing case unchanged — US1 A2/A3), `context.scope_check.outcome === 'accept'`, persisted row `scope_check_outcome === 'accept'` + `scope_check_reason`, `refusal` null (SC-002, FR-012)

**Checkpoint**: At this point, User Story 1 is functional and testable — in-scope inquiries are unchanged except for the additive scope marker.

---

## Phase 4: User Story 2 - Out-of-Scope Inquiries Are Stopped With the Reason (Priority: P1)

**Goal**: The gate `decline`s before classification and the visitor immediately gets a refusal whose message carries the specific, document-grounded reason in the existing response shape (FR-006, SC-001, SC-005).

**Independent Test**: `POST /inquiry/triage` with an out-of-scope inquiry returns a 200 refusal — `classification = "disqualify"`, `factor_scores = {}`, `reply` explains the reason — with `context.scope_check.outcome = "decline"`; the classification engine is never engaged.

### Implementation for User Story 2

- [X] T013 [US2] Implement the decline branch in `inquiry-handler/app/Http/Middleware/ScopeGateMiddleware.php`: stop the request **before** classification (SC-001), persist the decline record via `ClassificationResult::create` (message/name/email, `retrieved_context` from the gate's retrieval, `factor_scores {}`, `dropped_factors []`, `final_score 0.00`, `classification` `disqualify`, `reasoning` = scope reason, `scope_check_outcome` `decline`, `scope_check_reason`, `refusal` — FR-008/data-model.md), and return the refusal response in the existing top-level shape `{classification: 'disqualify', score: 0.0, factor_scores: {}, dropped_factors: [], reply: <refusal>, reasoning: <scope reason>, context: {inquiry, retrieved_context, scope_check: {outcome: 'decline', reason}}}` (FR-006, contracts/inquiry-web.md v2.1 — no new shape, no new status)
- [X] T014 [US2] Add decline-path feature tests to `inquiry-handler/tests/Feature/ScopeGateTest.php`: out-of-scope → 200 refusal with the shape above and `scope_check.outcome === 'decline'`; `reply` is a clear, non-generic reason grounded in the company scope (SC-005); the scoring engine did not run (no per-factor scores); repeated unrelated submissions are refused consistently (US2 A1–A3)

**Checkpoint**: At this point, User Story 2 is functional and testable — out-of-scope inquiries never reach classification and always get a reasoned refusal.

---

## Phase 5: User Story 3 - The Gate Handles Uncertain Scope Checks Gracefully (Priority: P1)

**Goal**: When retrieval or the AI is unavailable, returns zero relevant documents, or produces unreadable output, the gate reports `indeterminate` and **fails open** — the inquiry flows to full classification unchanged, never fabricated as accept/decline, never dropped (FR-004/FR-007, SC-003, Constitution III).

**Independent Test**: Force retrieval or the AI check to fail → the inquiry still reaches the full classification flow unchanged, with `context.scope_check.outcome = "indeterminate"`; invalid payloads still produce the pre-gate 400/422 (gate never screens an invalid payload).

### Implementation for User Story 3

- [X] T015 [US3] Confirm/harden every indeterminate branch in `inquiry-handler/app/ScopeGate/ScopeCheckService.php` (research R2/R4): RAG `ConnectionException`/timeout/HTTP non-success → `indeterminate`; `result_count === 0` (no grounding) → `indeterminate`; AI unreachable/timeout/`services.zai.key` missing → `indeterminate`; `complete()` `null` / unparseable / missing `in_scope` / missing `reason` on a false decision → `indeterminate`; visitor message + retrieved documents stay strictly user-role data (prompt-injection Edge Case, FR-003/FR-009) — extract shared guards/helpers if it clarifies the decision table, never coerce to accept/decline
- [X] T016 [US3] Ensure indeterminate pass-through in `inquiry-handler/app/Http/Middleware/ScopeGateMiddleware.php`: with the verdict stashed, `$next($request)` runs the full classification flow unchanged (fail open — nothing fabricated, nothing dropped); when `scope_gate.enabled` is false the response carries **no** `scope_check` marker (full bypass)
- [X] T017 [US3] Add indeterminate-path feature tests to `inquiry-handler/tests/Feature/ScopeGateTest.php`: RAG 503/5xx/timeout, RAG returning zero results, AI down/timeout/missing `ZAI_API_KEY`, AI returning garbage JSON → each returns a 200 classification with `context.scope_check.outcome === 'indeterminate'` and persisted `scope_check_outcome === 'indeterminate'` (US3 A1–A3); malformed non-object JSON → 400 and invalid payload → 422 with no screening (spec Edge Cases)

**Checkpoint**: At this point, all three gate outcomes (accept/decline/indeterminate) are covered and independently testable.

---

## Phase 6: User Story 4 - Declined Inquiries Remain Accountable (Priority: P2)

**Goal**: A declined inquiry never silently vanishes — a reviewer can later see the message, the refusal, the reason, and the scope-check outcome `decline` through the existing review surface; nothing is stored in a separate gate log (Clarifications Q1/Q4, FR-008, FR-012, SC-006/SC-007).

**Independent Test**: After an out-of-scope submission, the classification-results admin API returns the record with `inquiry_message`, `refusal`, and `scope_check_outcome = 'decline'`; accepted and indeterminate rows expose their `scope_check_outcome` too.

### Implementation for User Story 4

- [X] T018 [US4] Expose the scope columns read-only in the existing review API: confirm/extend `inquiry-handler/app/Services/ClassificationResultsService.php` and `inquiry-handler/app/Http/Controllers/AdminClassificationResultsController.php` (`/admin/classification-results` list + show) to surface `scope_check_outcome`, `scope_check_reason`, `refusal` alongside the existing fields (additive columns only — no separate gate log, FR-012/SC-007)
- [X] T019 [US4] Add accountability feature tests: extend `inquiry-handler/tests/Feature/ScopeGateTest.php` and/or `inquiry-handler/tests/Feature/ClassificationResultsAdminTest.php` — a declined inquiry leaves a record containing message, refusal, reason, and `scope_check_outcome === 'decline'`, returned by the admin API for later human review (US4 A1/A2, SC-006); accepted and indeterminate rows record `scope_check_outcome` as well (SC-007)

**Checkpoint**: At this point, all four user stories are functional and independently testable.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Full suite green, migration portability, quickstart validation, and SC-audits.

- [X] T020 Run the full inquiry-handler suite: `cd inquiry-handler && composer test` — all feature/unit tests green (new `ScopeGateTest` + untouched feature-006 tests)
- [X] T021 [P] Run `vendor/bin/pint` (Laravel Pint) on changed files in `inquiry-handler/` and fix any style violations
- [X] T022 [P] Run the quickstart.md manual validation scenarios end-to-end (in-scope accept, out-of-scope refusal with a DB record, RAG down → indeterminate, kill switch bypass)
- [X] T023 [P] SC-007 audit: confirm **both** write paths persist `scope_check_outcome` for every screened inquiry — accept/indeterminate via `InquiryTriageService` (T011), decline via `ScopeGateMiddleware` (T013) — and no separate gate log exists
- [X] T024 [P] FR-011 security pass: the gate consumes only existing `services.zai.*` and `services.company_scope` config — no new secrets committed, logged, or exposed; scope-check failure paths log no credentials or message content

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies, can start immediately.
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories (T006 is the only ScopeVerdict producer; T003/T004 are the only persistence).
- **User Stories (Phase 3+)**: All depend on Foundational. US1–US3 are the coupled P1 trio sharing the middleware file and verdict service; build in order (below). US4 depends on US2 (decline branch) but is P2.
- **Polish (Phase 7)**: Depends on all desired stories being implemented.

### User Story Dependencies (build order)

1. **US1 (T008–T012)** — middleware orchestration, routing, controller/service integration, accept marker + persistence; delivers the MVP (accept path).
2. **US2 (T013–T014)** — decline branch + refusal (extends the middleware created in T008; same file, sequential).
3. **US3 (T015–T017)** — indeterminate hardening (extends ScopeCheckService from T006 + middleware pass-through).
4. **US4 (T018–T019)** — reviewer visibility of all scope outcomes.
5. **Polish (T020–T024)**.

### Within Each User Story

- Core service/value object (Foundational) before integration (middleware) before tests
- Acceptance-path first, then decline, then uncertainty, then accountability
- Story complete before moving to the next priority

### Parallel Opportunities

- All `[P]` setup/foundational tasks run in parallel (disjoint files within their phase).
- `T002` ∥ `T001` (env vs config).
- `T003`/`T004` ∥ `T005`/`T007` (columns vs value object vs doc cleanup).
- `T009` (routing) ∥ `T010` (controller) once `T008` exists — then `T011`, then `T012` tests.
- Feature tests `T012`/`T014`/`T017`/`T019` are all [P]-eligible once their story's implementation lands.
- Polish audits `T021`–`T024` run in parallel after `T020`.

### Parallel Example: US1 integration

```text
Task: "T009 Register and route the middleware (bootstrap/app.php, routes/web.php)"
Task: "T010 Pass the verdict from InquiryController to InquiryTriageService"
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational — verdict service, columns, extraction).
2. Build US1 (middleware accept path + controller/service integration + marker). Gate only passes in-scope inquiries through.
3. **STOP and VALIDATE**: `composer test` and an in-scope curl via quickstart — response shape unchanged, `scope_check.outcome = accept`.
4. Deploy/demo if ready (gate is inert on the decline path until US2).

### Incremental Delivery

1. Setup + Foundational → verdict decision rules + persistence ready.
2. US1 → in-scope requests screened with an accept marker (MVP).
3. US2 → out-of-scope requests stopped with a reasoned refusal.
4. US3 → uncertain checks fail open to classification; failures never fabricate or drop.
5. US4 → reviewers see message/refusal/reason/outcome for every gate decision.
6. Polish → full suite green, quickstart validated, SC-audits pass.

### Parallel Team Strategy

- Team completes Setup + Foundational (T001–T007) together.
- Person A: US1 (T008–T012). Person B stands by for US2 (T013–T014) after T008 lands — sequential on the middleware file.
- Then Person C: US3 (T015–T017) while Person D: US4 (T018–T019).
- Polish audits in parallel.

---

## Notes

- [P] tasks = different files, no dependencies.
- [Story] label maps the task to a user story for traceability.
- The P1 trio (US1/US2/US3) shares `ScopeGateMiddleware`/`ScopeCheckService`; 006 precedent of sequential same-file story edits (e.g., `ScoringEngine` in US1/US4) applies.
- Tests are written alongside their implementation; the feature ships only with both suites green.
- No changes outside `inquiry-handler/`; no new services, tables, or secrets (FR-011, Constitution I).
- Commit after each task or logical group; stop at any checkpoint to validate independently.