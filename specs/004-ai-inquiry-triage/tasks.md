---

description: "Task list for AI Sales Inquiry Triage feature"
---

# Tasks: AI Sales Inquiry Triage

**Input**: Design documents from `/specs/004-ai-inquiry-triage/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: Included — the plan requires PHPUnit tests: one unit test per `app/Triage/*` class plus feature tests for the widget and triage flows via `Http::fake()` (TDD: write tests, watch them fail, then implement).

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

**v1 scope (per Clarifications)**: Escalation returns only the decision + a response to the inquirer — no persistence, webhook, email, operator log, or CRM hand-off; real mechanism deferred. The widget accepts **optional name + email** contact fields (valid email if provided); they are validated, preserved in context, and NEVER sent to the AI (data, not instructions).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US5, from spec.md)
- Include exact file paths in descriptions. Paths are relative to `inquiry-widget/` unless noted.

## Path Convention

New service lives at repository root: `inquiry-widget/` (config: `inquiry-widget/config/`, app code: `inquiry-widget/app/`, tests: `inquiry-widget/tests/`). Tests run with `cd inquiry-widget && ./vendor/bin/phpunit`.

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization for the new `inquiry-widget` service

- [x] T001 Create Laravel 13 project skeleton in `inquiry-widget/` (composer.json with laravel/framework `^13` + phpunit, artisan, bootstrap/, config/, app/, routes/, resources/, public/, storage/framework/{sessions,views,cache}, logs/) mirroring the existing `dashboard/` layout
- [x] T002 [P] Create `inquiry-widget/Dockerfile` (multi-stage: composer install → `php:8.4-cli` runtime, `php artisan serve --host=0.0.0.0 --port=8003`, ext ctype/mbstring/fileinfo/pdo/xml/pcntl) mirroring `dashboard/Dockerfile`
- [x] T003 [P] Create `inquiry-widget/.env.example` with `APP_URL=http://localhost:8003`, `GROQ_API_KEY=`, `GROQ_MODEL=llama-3.3-70b-versatile`, `AUTH_API_URL=http://auth-service:8001`, `RAG_API_URL=http://work-scope-rag:8000`, `SERVICE_USERNAME=`, `SERVICE_PASSWORD=`, `BOOKING_URL=`, `MESSAGE_MAX_LENGTH=4000`, `RAG_TOP_K=5`
- [x] T004 [P] Create `inquiry-widget/docker-entrypoint.sh` (generate APP_KEY if unset, chmod storage, artisan package:discover + config:cache + route:cache + view:cache, `exec "$@"`) mirroring `dashboard/docker-entrypoint.sh`
- [x] T005 [P] Add `inquiry-widget` service to `docker-compose.yml` (port `8003:8003`, env_file `./inquiry-widget/.env`, healthcheck GET `/health`, `depends_on` auth-service + work-scope-rag with `condition: service_healthy`)
- [x] T006 Configure `inquiry-widget/config/services.php` to read `auth_api_url`, `rag_api_url`, `groq_api_key`, `groq_model`, `service_username`, `service_password`, `booking_url` from env, and add `message_max_length` + `rag_top_k` to `inquiry-widget/config/app.php`
- [x] T007 Create `HealthController` (`GET /health`) in `inquiry-widget/app/Http/Controllers/HealthController.php` and register route in `inquiry-widget/routes/web.php`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core building blocks that MUST exist before ANY user story can be implemented

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [x] T008 [P] Create `Disposition` enum (`decline|escalate|booking`) in `inquiry-widget/app/Triage/Disposition.php`
- [x] T009 [P] Create `TriageResult` DTO (`disposition`, `reply`, `reasoning`, `context`) in `inquiry-widget/app/Triage/TriageResult.php`
- [x] T010 [P] Create `AuthApiClient` (`POST /auth/login` for the service account, returns token/expiry without throwing on responses) in `inquiry-widget/app/Services/AuthApiClient.php`
- [x] T011 [P] Create `ServiceToken` (in-memory cached bearer token; re-login on expiry/absent; `token()` accessor) in `inquiry-widget/app/Support/ServiceToken.php`
- [x] T012 Create `RagApiClient` (`POST /query` with `query` + `top_k=RAG_TOP_K`, attaches `Authorization: Bearer` from `ServiceToken`, no throw-on) in `inquiry-widget/app/Services/RagApiClient.php`
- [x] T013 Create `MessageExtractor` (plain-text branch `{"message": "...", "name"?: "...", "email"?: "..."}`; extracts canonical message PLUS optional contact fields; blank-after-trim and >`MESSAGE_MAX_LENGTH` handling; malformed-email rejection; TODO seam for structured schemas) in `inquiry-widget/app/Triage/MessageExtractor.php`
- [x] T014 Create `PromptBuilder` (fixed system-prompt constant strictly separated from `[USER INQUIRY]` and `[RETRIEVED DOCUMENTS]` data blocks; explicit "no retrieved documents" block when RAG returns zero results; contact fields NEVER included) in `inquiry-widget/app/Triage/PromptBuilder.php`
- [x] T015 Create `AiCallingService` (Groq Chat Completions via `Http::` → `https://api.groq.com/openai/v1/chat/completions` with `GROQ_API_KEY`/`GROQ_MODEL`, `response_format=json_object`, temp 0, retry without `response_format` on provider 422, strict JSON parse → normalized `{disposition, reply, reasoning, raw_ok}`) in `inquiry-widget/app/Services/AiCallingService.php`

**Checkpoint**: Foundation ready — user story implementation can now begin in parallel

---

## Phase 3: User Story 1 - Visitor Submits a Sales Inquiry (Priority: P1) 🎯 MVP

**Goal**: A visitor submits a sales inquiry through the widget (message + optional name/email); it is extracted, grounded via RAG `POST /query`, passed to the AI with a clear system-prompt boundary, and the visitor receives **one of the three dispositions** (decline/escalate/booking) with a reply.

**Independent Test**: Submit a valid inquiry via `POST /inquiry/triage` against faked RAG + Groq responses and verify a 200 response with exactly one `disposition` and a `reply`; the widget page renders at `GET /` with message + optional name/email fields.

### Tests for User Story 1 (write FIRST, verify FAIL before implementation) ⚠️

- [x] T016 [P] [US1] Unit test `PromptBuilderTest` in `inquiry-widget/tests/Unit/PromptBuilderTest.php` (asserts system-prompt constant is separate from user-inquiry and retrieved-documents data blocks; no-answer block on zero results; contact fields from the inquiry are never present in the assembled prompt — FR-004/FR-012)
- [x] T017 [P] [US1] Feature test `WidgetPageTest` in `inquiry-widget/tests/Feature/WidgetPageTest.php` (`GET /` returns 200, renders the required message input and the optional name + email inputs and the submit control — FR-001)
- [x] T018 [P] [US1] Feature test `InquiryTriageTest` in `inquiry-widget/tests/Feature/InquiryTriageTest.php` using `Http::fake()` (in-scope inquiry → disposition returned with `context.retrieved_context`; name/email accepted and echoed in `context.inquiry`; submission without name/email succeeds with those fields absent from context; valid email only; blank/oversized/missing message and malformed email → 422; error envelope when triage service unavailable)

### Implementation for User Story 1

- [x] T019 [P] [US1] Implement `Dispatcher` (strict map `disposition → handler`; ANY unknown/unparseable value routes to `EscalateHandler`) in `inquiry-widget/app/Triage/Dispatcher.php`
- [x] T020 [P] [US1] Implement `EscalateHandler` (preserves inquiry + retrieved context; v1 reply indicates the escalation decision was taken and performs no further action — per Clarifications) in `inquiry-widget/app/Triage/Handlers/EscalateHandler.php`
- [x] T021 [P] [US1] Implement `DeclineHandler` (polite out-of-scope reply) in `inquiry-widget/app/Triage/Handlers/DeclineHandler.php`
- [x] T022 [P] [US1] Implement `BookingHandler` (booking reply embedding `BOOKING_URL`; escalates if URL unset — FR-009) in `inquiry-widget/app/Triage/Handlers/BookingHandler.php`
- [x] T023 [US1] Implement `InquiryTriageService` orchestrator (extract → RAG → PromptBuilder → AiCallingService → Dispatcher; wraps ambiguity/failures so they resolve to `escalate` with context preserved) in `inquiry-widget/app/Services/InquiryTriageService.php`
- [x] T024 [US1] Implement `InquiryController` (`POST /inquiry/triage`: validate message + optional name/email via `MessageExtractor`, call `InquiryTriageService`, return `TriageResult` JSON; 400/422/503 error handling per `contracts/inquiry-web.md`) in `inquiry-widget/app/Http/Controllers/InquiryController.php` and register route in `inquiry-widget/routes/web.php`
- [x] T025 [US1] Create widget view `inquiry-widget/resources/views/inquiry/index.blade.php` + `inquiry-widget/resources/views/layouts/app.blade.php` (message input + optional name/email inputs, fetch to `/inquiry/triage` sending all three fields, result area rendering `reply`) and `GET /` route

**Checkpoint**: User Story 1 is fully functional and testable independently — this is the MVP

---

## Phase 4: User Story 2 - Escalate Unclear or High-Value Inquiries to a Human (Priority: P1)

**Goal**: Any ambiguity or system failure lands on the escalate disposition (escalate-by-default), preserving the raw inquiry + retrieved context and informing the inquirer that the decision was taken. For v1 escalate performs no further action (no persistence, webhook, email, operator log, or CRM hand-off — per Clarifications). Nothing is silently dropped or guessed.

**Independent Test**: Force failures (RAG `POST /query` returns 401/503, Groq returns non-2xx/timeout/`GROQ_API_KEY` garbage, AI returns unparseable or unknown-disposition JSON) and verify `POST /inquiry/triage` responds with `disposition: "escalate"`, the preserved `context`, and a reply indicating the decision was taken — with no persistence/webhook/operator-log side effect.

### Tests for User Story 2 (write FIRST, verify FAIL before implementation) ⚠️

- [x] T026 [P] [US2] Unit test `DispatcherTest` in `inquiry-widget/tests/Unit/DispatcherTest.php` (unknown/disposition-missing/`null` values → EscalateHandler; each known value → its handler)
- [x] T027 [P] [US2] Unit test `EscalateHandlerTest` in `inquiry-widget/tests/Unit/EscalateHandlerTest.php` (inquiry + retrieved context preserved in result.context; reply non-empty and indicates the decision was taken; result triggers no persistence/hand-off side effect)
- [x] T028 [P] [US2] Feature test `FailurePathsTest` in `inquiry-widget/tests/Feature/FailurePathsTest.php` using `Http::fake()` (RAG 401→re-auth then success, RAG 503, Groq 500/timeout/wrong key, unparseable JSON → all produce `escalate` with the decision-response, never 500, never fabricated disposition)

### Implementation for User Story 2

- [x] T029 [US2] Implement token re-auth + single retry on RAG `401` in `inquiry-widget/app/Services/RagApiClient.php` + `inquiry-widget/app/Support/ServiceToken.php` (refresh service-account token once; second 401 → escalate path)
- [x] T030 [US2] Verify/confirm the v1 escalation boundary in `inquiry-widget/app/Services/InquiryTriageService.php` + `inquiry-widget/app/Triage/Handlers/EscalateHandler.php`: escalate produces ONLY the decision response to the inquirer — no persistence, no webhook, no operator log, no CRM call (research §7; Clarifications); add a code comment marking the deferred escalation mechanism with a TODO

**Checkpoint**: User Stories 1 AND 2 work together — ambiguity and failures escalate by default with full context and an honest decision response

---

## Phase 5: User Story 3 - Send a Booking Link for Qualified Inquiries (Priority: P2)

**Goal**: Meeting-ready inquiries receive the configured booking link; if the link is not configured, the inquiry escalates instead of presenting a broken link (FR-009).

**Independent Test**: Fake Groq returning `"disposition":"booking"` and verify the reply contains a usable `BOOKING_URL` when configured, and escalates when `BOOKING_URL` is unset.

### Tests for User Story 3 (write FIRST, verify FAIL before implementation) ⚠️

- [x] T031 [P] [US3] Unit test `BookingHandlerTest` in `inquiry-widget/tests/Unit/BookingHandlerTest.php` (with `BOOKING_URL` → link present in reply; without → resolve to escalate per Dispatcher contract)

### Implementation for User Story 3

- [x] T032 [US3] Finalize `BookingHandler` booking-link gating in `inquiry-widget/app/Triage/Handlers/BookingHandler.php` (embed `config('services.booking_url')`; when empty, return escalate outcome with context — FR-009)

**Checkpoint**: Booking disposition is safe — never references an unconfigured link (SC-5)

---

## Phase 6: User Story 4 - Decline Out-of-Scope Inquiries Gracefully (Priority: P2)

**Goal**: Off-topic / non-sales / noise inquiries receive a courteous decline only — no escalation, no booking link (FR-006; SC-4).

**Independent Test**: Submit clearly unrelated messages and verify each returns `disposition: "decline"` with a polite reply and no booking/escalation behavior.

### Tests for User Story 4 (write FIRST, verify FAIL before implementation) ⚠️

- [x] T033 [P] [US4] Unit test `DeclineHandlerTest` in `inquiry-widget/tests/Unit/DeclineHandlerTest.php` (polite reply; no booking link anywhere in result; no escalation)

### Implementation for User Story 4

- [x] T034 [US4] Polish `DeclineHandler` reply in `inquiry-widget/app/Triage/Handlers/DeclineHandler.php` (polite scope explanation; consistent copy for repeated off-topic messages; optionally reference "no retrieved documents" case)

**Checkpoint**: Decline disposition is consistent, polite, and inert (US-4 acceptance scenarios)

---

## Phase 7: User Story 5 - Extract the Message From a Structured Payload (Priority: P3)

**Goal**: A structured payload (future external-app schema) normalizes to the same canonical message (plus optional contact fields when the schema carries them) the plain-text path produces, so triage behaves identically regardless of envelope (FR-013).

**Independent Test**: Submit a structured payload (e.g. `{"payload":{"text":"...","source":"crm-x"}}`) and confirm the extracted canonical message equals the plain-text canonical message and produces the same disposition.

### Tests for User Story 5 (write FIRST, verify FAIL before implementation) ⚠️

- [ ] T035 [P] [US5] Unit test `MessageExtractorTest` in `inquiry-widget/tests/Unit/MessageExtractorTest.php` (plain-text passthrough; optional name/email passthrough, malformed email rejection, name/email absent when not provided; structured-schema extraction equals canonical message; blank/oversized rejection)

### Implementation for User Story 5

- [ ] T036 [US5] Add structured-schema branch to `inquiry-widget/app/Triage/MessageExtractor.php` (documented TODO seam; normalize a defined external-app payload shape into the same canonical `message` string — and optional contact fields when the schema carries them — with identical validation)

**Checkpoint**: All user stories independently functional

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Improvements that affect multiple user stories

- [x] T037 [P] Run the full suite green: `cd inquiry-widget && ./vendor/bin/phpunit` (all Feature + Unit tests pass; zero secrets in output)
- [ ] T038 [P] Run `specs/004-ai-inquiry-triage/quickstart.md` validation scenarios end-to-end against the Docker Compose stack (widget render incl. name/email fields, decline/escalate/booking, failure paths, 422s incl. malformed email, SC-1..SC-6)
- [x] T039 Security review: `rg -i 'api[_-]?key\|password\|secret' inquiry-widget/ --glob '!vendor/**' --glob '!.env.example'` shows no committed secrets; `GROQ_API_KEY`/`SERVICE_PASSWORD` only via env (spec FR-011); prompt-boundary verified by `PromptBuilderTest`; contact fields never leak into AI prompt
- [x] T040 Create `inquiry-widget/README.md` (env vars, `docker compose up`, endpoints, how to swap Groq model/provider, v1 escalation scope note)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately
- **Foundational (Phase 2)**: Depends on Setup; **BLOCKS all user stories**
- **User Stories (Phase 3+)**: All depend on Foundational. US1 → US2 (both P1) → US3/US4 (P2) → US5 (P3); sequential in priority order, or in parallel once Foundational lands.
- **Polish (Phase 8)**: Depends on the stories planned for this release

### User Story Dependencies

- **US1 (P1)**: Starts after Foundational — no story dependencies (MVP)
- **US2 (P1)**: Starts after Foundational; stricter safety net over US1's escalate path — treat as production release-gating alongside US1
- **US3 (P2)**: Independent; refines `BookingHandler` created in US1
- **US4 (P2)**: Independent; refines `DeclineHandler` created in US1
- **US5 (P3)**: Independent; extends the `MessageExtractor` seam from US1

### Within Each User Story

- Tests are written FIRST and FAIL before implementation (TDD)
- Models/DTOs before services; services before controllers/routes; integration last
- Each story is complete, testable, and demonstrable before moving to the next

### Parallel Opportunities

- Setup T002–T005 (all different files) run in parallel; same for Foundational T008–T011
- Within US1: tests T016–T018 then handlers T019–T022 (all [P]) can be parallelized
- US2/US3/US4/US5 tests and handlers are all independently parallelizable across stories
- US3, US4, US5 can proceed in parallel once Foundational + US1 land

---

## Parallel Example: User Story 1

```bash
# Launch all US1 tests together (write FIRST, confirm they FAIL):
Task: "Unit test PromptBuilderTest in inquiry-widget/tests/Unit/PromptBuilderTest.php"
Task: "Feature test WidgetPageTest in inquiry-widget/tests/Feature/WidgetPageTest.php"
Task: "Feature test InquiryTriageTest in inquiry-widget/tests/Feature/InquiryTriageTest.php"

# Launch all US1 handler/component implementations together (distinct files):
Task: "Implement Dispatcher in inquiry-widget/app/Triage/Dispatcher.php"
Task: "Implement EscalateHandler in inquiry-widget/app/Triage/Handlers/EscalateHandler.php"
Task: "Implement DeclineHandler in inquiry-widget/app/Triage/Handlers/DeclineHandler.php"
Task: "Implement BookingHandler in inquiry-widget/app/Triage/Handlers/BookingHandler.php"
```

**Parallel: US2 failure test bundle (all Http::fake, distinct test files/classes):**

```bash
Task: "Unit test DispatcherTest in inquiry-widget/tests/Unit/DispatcherTest.php"
Task: "Unit test EscalateHandlerTest in inquiry-widget/tests/Unit/EscalateHandlerTest.php"
Task: "Feature test FailurePathsTest in inquiry-widget/tests/Feature/FailurePathsTest.php"
```

---

## Implementation Strategy

### MVP First (User Stories 1 + 2)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL — blocks all stories)
3. Complete Phase 3: User Story 1 (end-to-end widget → disposition)
4. Complete Phase 4: User Story 2 (escalate-by-default safety net — P1, requires release-gating)
5. **STOP and VALIDATE**: `./vendor/bin/phpunit` green + quickstart scenarios 2, 5, 7 green
6. Deploy/demo before P2/P3 stories

### Incremental Delivery

1. Setup + Foundational → Foundation ready
2. US1 → decline/escalate/booking routing works → MVP
3. US2 → ambiguity/failure safety guaranteed → production-ready core
4. US3 → booking link delivery → US4 → decline polish → US5 → structured payloads
5. Polish (Phase 8) → full quickstart sign-off

### Parallel Team Strategy

With multiple developers: team completes Setup + Foundational together; once Foundational is done:
- Developer A: US1 (core pipeline)
- Developer B: US2 (escalate-by-default + failure paths) — parallelizable once US1 handlers exist
- Developer C: US3 + US4 (booking + decline refinements)
- Developer D: US5 (message extraction seam)
Stories integrate independently; each has its own green test suite.

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps to spec user stories for traceability
- Each user story is independently completable and testable
- Verify tests FAIL before implementing (TDD per story)
- Commit after each task or logical group
- Stop at any checkpoint to validate the story independently
- Avoid: vague tasks, same-file conflicts, cross-story dependencies that break independence
- v1 scope reminders: escalation = decision response only (no persistence/webhook/log); contact fields are optional, validated, preserved in context, and never sent to the AI
- New service mirrors `dashboard/` conventions (Dockerfile, entrypoint, Http client patterns) exactly where the stack is identical

## Status (2026-09-12)

- T001–T034, T037, T039, T040: **complete**. `./vendor/bin/phpunit` green (59 tests / 137 assertions);
  `inquiry-widget` image builds and runs: `GET /health` 200, widget page renders name/email/message,
  400/422 handling verified in-container, and escalate-by-default (RAG down → 200 `escalate` with full
  preserved context) verified in-container.
- T035 / T036 (US5, P3): **deferred** — no external structured-payload schema is defined, so no speculative
  adapter exists. The `MessageExtractor` seam is prepared with a `TODO` (US5) that maps a future envelope
  onto the canonical shape. `MessageExtractorTest` covers the plain-text + contact-field behaviors only.
- T038: **blocked on credentials** — end-to-end quickstart scenarios need a real `GROQ_API_KEY` and a
  service-account user registered in `auth-service`; the full stack (`db` + `auth-service` + `work-scope-rag`)
  must be running. Partially exercised: `docker compose config` valid; widget image build + container
  smoke tests above. Re-run `quickstart.md` SC-1..SC-6 once secrets are provided.
- Note: repository root is not a git repo, so the "commit after each task" convention could not be applied.