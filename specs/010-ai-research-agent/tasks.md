---

description: "Task list for AI Research Agent for Company & Person Enrichment"
---

# Tasks: AI Research Agent for Company & Person Enrichment

**Input**: Design documents from `/specs/010-ai-research-agent/`

**Prerequisites**: plan.md (required), spec.md (required for user stories),
research.md, data-model.md, contracts/, quickstart.md

**Tests**: Included — the spec makes test-asserted promises (SC-007, plus
Stories 1–4 acceptance scenarios) and plan.md defines a testing strategy. Test
tasks are marked with the `⚠️` write-first note within each story.

**Organization**: Tasks are grouped by user story so each story is
independently implementable and testable. All paths are relative to the
`inquiry-handler/` service root unless prefixed with `specs/`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: `[US1]`…`[US5]`, mapping to the spec's user stories

## Path Conventions

- Service code: `inquiry-handler/app/...`
- Config: `inquiry-handler/config/...`, `inquiry-handler/.env.example`
- Tests: `inquiry-handler/tests/...`
- Design docs: `specs/010-ai-research-agent/...`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Add the agent's budget/fetch configuration surface.

- [X] T001 Add agent knobs to `inquiry-handler/config/web_research.php`: `max_candidates` (12), `max_sources` (5), `fetch_timeout` (8), `fetch_max_bytes` (200000), `source_max_chars` (8000), `summary_max_input_chars` (24000), `step_timeout` (25), each `env('WEB_RESEARCH_*', default)` per research.md R7.
- [X] T002 [P] Document the new keys in `inquiry-handler/.env.example` with sane defaults (no secrets) per research.md R7.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Domain types and the agent seam that every user story builds on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T003 [P] Create `App\WebResearch\ResearchOutcome` enum in `inquiry-handler/app/WebResearch/ResearchOutcome.php` with cases `completed`, `partial`, `not_found`, `ambiguous`, `indeterminate` (data-model.md).
- [X] T004 [P] Create `App\WebResearch\ResearchResult` value object in `inquiry-handler/app/WebResearch/ResearchResult.php` with `outcome`, `summary`, `sources`, `limitations`, plus `toFindings(): array` returning the persisted `{outcome, summary, sources, limitations}` shape (contracts/research-agent.md §5).
- [X] T005 Create `App\WebResearch\ResearchAgent` in `inquiry-handler/app/WebResearch/ResearchAgent.php` with constructor injection of `App\Services\AiCallingService` and `App\WebResearch\PageFetcher`, fixed system-prompt constants, and a `research(array $criteria, array $payload): ResearchResult` that currently returns `ResearchOutcome::Indeterminate` (skeleton; implemented per story).

**Checkpoint**: Domain types compile and the DI seam exists — user stories can begin.

---

## Phase 3: User Story 1 — One Filtered Research Summary for the Named Company/Person (Priority: P1) 🎯 MVP

**Goal**: Gather candidates with the existing provider, AI-filter them to the
named company/person, and return one source-cited summary (raw lists dropped).

**Independent Test**: Submit an inquiry naming a findable company + person and
assert `context.web_research.findings` is `{outcome: completed, summary, sources}`
referencing only the named entity, with no `company`/`person` result lists.

### Tests for User Story 1 (write first, must fail) ⚠️

- [X] T006 [P] [US1] Create `inquiry-handler/tests/Unit/ResearchAgentTest.php` covering the filter call: candidates flattened and capped at `max_candidates`, target/candidates sent only in the user role, `keep` ids selected and unknown ids ignored, kept sources capped at `max_sources`, and mocked `AiCallingService` returning canned JSON (research.md R2/R8).
- [X] T007 [P] [US1] Update `inquiry-handler/tests/Unit/WebResearchServiceTest.php` for the new `WebResearchService(WebResearchProvider, ResearchAgent)` constructor and the new findings shape (`assertSame('completed', $verdict->research['outcome'])`, `summary`, `sources`), keeping the criteria/privacy tests.

### Implementation for User Story 1

- [X] T008 [US1] Implement the filter step in `inquiry-handler/app/WebResearch/ResearchAgent.php`: flatten provider `company`/`person` `results` into Candidate Results, build the fixed system prompt + data-block user message, call `AiCallingService::complete()`, parse `outcome`/`keep`/`reason`, and map to kept Candidate Results.
- [X] T009 [US1] Implement the summarize step in `inquiry-handler/app/WebResearch/ResearchAgent.php`: build the fixed summarize system prompt + target/documents data blocks, call `complete()`, validate a non-empty string `summary`, re-map `sources` to URLs actually used, and build `ResearchResult` (initially from candidate title/snippet documents; fetch grounding lands in US2).
- [X] T010 [US1] Wire the agent into `inquiry-handler/app/WebResearch/WebResearchService.php`: inject `ResearchAgent`, keep `criteria()`/`normalize()` and the provider `decline` short-circuit, call the agent for the accept path, and return `WebResearchVerdict::accept($result->toFindings())`; on `indeterminate` return `WebResearchVerdict::indeterminate(...)` (research.md R1/R5).
- [X] T011 [US1] Extend `inquiry-handler/tests/Feature/WebResearchMiddlewareTest.php` accept case: fake the provider + agent so `context.web_research.findings` is `{outcome: completed, summary, sources}`, assert raw `findings.company`/`findings.person` are absent in the response and the persisted row.

**Checkpoint**: The named company/person yields one filtered summary — MVP demoable.

---

## Phase 4: User Story 2 — The Agent Fetches Source Data and Grounds the Summary (Priority: P1)

**Goal**: Fetch bounded page content of kept sources and ground the summary in it,
skipping failed fetches without aborting.

**Independent Test**: Point a kept source at a page whose content differs from its
snippet and assert the summary reflects fetched content; a failing source yields
`partial` and is skipped.

### Tests for User Story 2 (write first, must fail) ⚠️

- [X] T012 [P] [US2] Create `inquiry-handler/tests/Unit/PageFetcherTest.php` using `Http::fake()`: HTML scrubbed to text with script/style/comments removed and whitespace collapsed; text capped at `source_max_chars`; non-2xx, timeout, non-HTML content type, and oversize body all return `null`; only `http`/`https` are fetched (research.md R3/R8).
- [X] T013 [P] [US2] Extend `inquiry-handler/tests/Unit/ResearchAgentTest.php`: kept sources are fetched and their text feeds the summarize call; one failing fetch yields `ResearchOutcome::Partial`; `sources` in the result contain only successfully fetched URLs.

### Implementation for User Story 2

- [X] T014 [US2] Create `inquiry-handler/app/WebResearch/PageFetcher.php`: Laravel HTTP client fetch with `fetch_timeout`, redirect cap, `Accept: text/html`, 2xx + HTML/text guard, `fetch_max_bytes` body cap, and HTML→text extraction (strip script/style/noscript/comments/tags, decode entities, collapse whitespace) capped at `source_max_chars`; returns `['title','url','text']` or `null` (contracts/research-agent.md §3).
- [X] T015 [US2] Implement the fetch step in `inquiry-handler/app/WebResearch/ResearchAgent.php`: fetch each kept source via `PageFetcher`, drop `null` results, pass fetched documents to summarize (bounded by `summary_max_input_chars`), set `partial` when some kept sources fail, and re-map `sources` against the fetched set.

**Checkpoint**: Summaries are grounded in fetched page content; failures skip cleanly.

---

## Phase 5: User Story 3 — Honest Handling of Missing, Ambiguous, or Unfindable Entities (Priority: P2)

**Goal**: Never guess or merge entities; state `not_found`/`ambiguous` explicitly
with no fabricated facts and still return HTTP 200.

**Independent Test**: Submit a clearly non-existent company and assert the result
states it could not be found, contains no invented facts, and completes with 200.

### Tests for User Story 3 (write first, must fail) ⚠️

- [X] T016 [P] [US3] Extend `inquiry-handler/tests/Unit/ResearchAgentTest.php`: filter `outcome=not_found`/empty `keep` → `ResearchOutcome::NotFound` with a statement summary and no summarize AI call; `outcome=ambiguous` → `ResearchOutcome::Ambiguous`; blank company runs person-only without inventing a company; blank person runs company-only.
- [X] T017 [P] [US3] Extend `inquiry-handler/tests/Feature/WebResearchMiddlewareTest.php`: a `not_found` result returns HTTP 200 with `context.web_research.findings.outcome = 'not_found'` and a non-empty statement `summary`.

### Implementation for User Story 3

- [X] T018 [US3] Implement honest-outcome handling in `inquiry-handler/app/WebResearch/ResearchAgent.php`: map filter `not_found`/empty-`keep` → `NotFound`, `ambiguous` → `Ambiguous`; build explicit visitor-safe statement summaries for both and skip the summarize AI call; handle blank company/person criteria without fabricating the missing entity.

**Checkpoint**: Unfindable/ambiguous entities are reported honestly.

---

## Phase 6: User Story 4 — Agent Failures Never Break or Block the Inquiry (Priority: P2)

**Goal**: Every provider/AI/fetch failure and over-budget run degrades fail-open
(`partial`/`indeterminate`) without blocking the inquiry.

**Independent Test**: Force the provider and/or AI to fail and assert the inquiry
still completes with `context.web_research.outcome = indeterminate` and no
fabricated summary.

### Tests for User Story 4 (write first, must fail) ⚠️

- [X] T019 [P] [US4] Extend `inquiry-handler/tests/Unit/ResearchAgentTest.php`: filter AI call returns `null` → `Indeterminate`; summarize call returns `null`/empty summary → `Indeterminate`; zero candidates → no AI call and `Indeterminate`; every fetch failing → `Indeterminate`; a run hitting `step_timeout`/budget degrades rather than hanging.
- [X] T020 [P] [US4] Extend `inquiry-handler/tests/Feature/WebResearchMiddlewareTest.php`: AI/provider failure leaves `context.web_research.outcome = indeterminate` with empty findings and the inquiry still classifies (`200`); update the existing indeterminate assertions from `findings.company = []` to the new empty-findings shape.

### Implementation for User Story 4

- [X] T021 [US4] Implement budget/timeout and degradation in `inquiry-handler/app/WebResearch/ResearchAgent.php`: guard every AI/fetch call, treat malformed/unusable AI output as failure, enforce `step_timeout` and per-run caps, and always return a `ResearchResult` (never throw).
- [X] T022 [US4] Confirm and document fail-open in `inquiry-handler/app/WebResearch/WebResearchService.php`: the existing provider `Throwable` wrapper remains, agent `Indeterminate` → `WebResearchVerdict::indeterminate(...)`, and the provider `decline` short-circuit is untouched (research.md R5).

**Checkpoint**: The inquiry is unblockable — all failures degrade gracefully.

---

## Phase 7: User Story 5 — Every Agent Run Is Auditable (Priority: P3)

**Goal**: Persist and expose the criteria, kept sources, summary, and outcome so a
reviewer can reconstruct the conclusion.

**Independent Test**: Run the agent, retrieve the persisted record via the admin
API, and confirm it contains criteria, kept sources, summary, and outcome.

### Tests for User Story 5 (write first, must fail) ⚠️

- [X] T023 [P] [US5] Update `inquiry-handler/tests/Feature/ClassificationResultsAdminTest.php` seeded `web_research.findings` fixtures to the new `{outcome, summary, sources, limitations}` shape and assert the detail response exposes it.
- [X] T024 [P] [US5] Extend `inquiry-handler/tests/Feature/Support/UpstreamStubs.php` with helpers to fake `AiCallingService` (filter + summarize) and `PageFetcher`/`ResearchAgent` results for feature tests.

### Implementation for User Story 5

- [X] T025 [US5] Verify the pass-through path persists and returns the new shape with no functional change needed: `inquiry-handler/app/Services/InquiryTriageService.php`, `inquiry-handler/app/Http/Middleware/ScopeGateMiddleware.php`, and `inquiry-handler/app/Http/Middleware/WebResearchMiddleware.php` all forward `$verdict->research` as `findings`; update their docblocks/type hints (and `inquiry-handler/app/Models/ClassificationResult.php`) to describe the new criteria+findings shape.

**Checkpoint**: Every run is inspectable via the persisted record and admin API.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Whole-feature verification and consistency.

- [X] T026 [P] Reconcile docblocks/contract references in `inquiry-handler/app/WebResearch/WebResearchVerdict.php`, `WebResearchProvider.php`, and `Providers/TavilyResearchProvider.php` with the new findings shape (no behavior change).
- [X] T027 Run `cd inquiry-handler && php artisan test` and fix any regressions across the full suite.
- [ ] T028 Execute the `specs/010-ai-research-agent/quickstart.md` scenarios 1–6 (completed, not_found, ambiguous, fail-open, kill switch, admin detail) and record results. Scenarios 2–6 are covered by the automated suite; scenario 1 needs a live Docker stack + real Tavily/ZAI keys.
- [ ] T029 [P] Verify SC-007 privacy: assert no outbound provider/AI/fetch request contains email or phone (unit + a live `docker compose logs inquiry-handler` inspection during scenario 1). Unit coverage is green in `WebResearchServiceTest`/`ResearchAgentTest`; the live log inspection is pending a running stack.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately.
- **Foundational (Phase 2)**: Depends on Setup — blocks all stories.
- **User Stories (Phase 3+)**: Depend on Foundational. US1 and US2 are both P1;
  US2 builds on US1's agent. US3–US5 build on US1/US2.
- **Polish (Phase 8)**: Depends on all targeted stories complete.

### User Story Dependencies

- **US1 (P1)**: After Foundational — no dependency on other stories (MVP).
- **US2 (P1)**: After US1 (adds the fetch stage to the same agent).
- **US3 (P2)**: After US1 (adds honest outcome mapping).
- **US4 (P2)**: After US1 (hardens the same pipeline).
- **US5 (P3)**: After US1 (persists/exposes the produced shape).

> **Same-file note**: US1–US4 all extend
> `inquiry-handler/app/WebResearch/ResearchAgent.php`, so they are **not**
> file-parallel despite being independent in behavior; run them sequentially in
> priority order. Cross-story parallelism is possible across test files.

### Within Each User Story

- Tests (T006/T007, T012/T013, T016/T017, T019/T020, T023/T024) are written first
  and must fail before implementation.
- Enum/VO/agent seam (Phase 2) before agent pipeline tasks.
- Filter before summarize before fetch wiring.
- Story complete and green before moving to the next priority.

### Parallel Opportunities

- T002 (env example) can proceed alongside T001.
- T003 and T004 (enum + value object) are independent files.
- Test files marked `[P]` within a story are independent (different files).
- T026 and T029 are independent polish tasks.

---

## Parallel Example: User Story 1

```bash
# Write both US1 test files together (different files, no dependencies):
Task: "Create tests/Unit/ResearchAgentTest.php covering the AI filter call"
Task: "Update tests/Unit/WebResearchServiceTest.php for the new constructor and findings shape"

# Then implement the agent steps sequentially (same file):
Task: "Implement the filter step in app/WebResearch/ResearchAgent.php"
Task: "Implement the summarize step in app/WebResearch/ResearchAgent.php"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Complete Phase 1: Setup.
2. Complete Phase 2: Foundational (blocks all stories).
3. Complete Phase 3: US1 — filtered source-cited summary.
4. **STOP and VALIDATE**: run the US1 tests and quickstart scenario 1.
5. Demo/deploy the filtered summary.

### Incremental Delivery

1. Setup + Foundational → foundation ready.
2. US1 → filtered summary (MVP).
3. US2 → fetch-grounded summary + `partial`.
4. US3 → honest `not_found`/`ambiguous`.
5. US4 → fail-open/budget hardening.
6. US5 → auditability + admin contract.
7. Polish → full suite + quickstart.

---

## Notes

- `[P]` = different files, no dependencies.
- `[Story]` maps tasks to spec user stories for traceability.
- Tests are included because the spec asserts test-backed guarantees (SC-007) and
  plan.md defines the test files; verify they fail before implementing.
- Commit after each task or logical group; do not commit secrets (`.env`).
- Stop at any checkpoint to validate a story independently.
