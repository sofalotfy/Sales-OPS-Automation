# Tasks: Rename Inquiry Widget to Inquiry Handler

**Input**: Design documents from `/specs/005-rename-inquiry-handler/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Tests**: Included — spec FR-008 explicitly requires the existing automated suite to stay green with naming assertions updated (no coverage loss), so test update/verification tasks are part of each story phase.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Path Conventions

- Target service directory: `inquiry-handler/` (renamed from `inquiry-widget/` by T001 — later tasks assume the move is done; paths below are the post-move paths).
- Tests run with `cd inquiry-handler && ./vendor/bin/phpunit`.
- Historical `specs/004-*` documents are OUT OF SCOPE — never edited (user decision, research §4).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1..US4)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Physically relocate the service and fix package/identity metadata so every later task operates on the renamed tree.

- [X] T001 Move the service directory `inquiry-widget/` → `inquiry-handler/` (filesystem move preserving `.env`, `.gitignore`, `vendor/`, and all source files — research §2)
- [X] T002 Update composer package metadata in `inquiry-handler/composer.json` (name `sales-ops/inquiry-handler`; description/keywords drop "widget")
- [X] T003 Regenerate lock + autoload in `inquiry-handler/` (`composer update --lock`) so `composer.lock` and `vendor/composer/installed.php` reflect the new package name

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Runtime identity and app label that every user story builds on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T004 Rename the compose service in `docker-compose.yml` (service key `inquiry-widget` → `inquiry-handler`, `container_name`, build `context: ./inquiry-handler`, `env_file: ./inquiry-handler/.env`) — port `8003:8003` and healthcheck unchanged
- [X] T005 Update identity + comments in `inquiry-handler/.env.example` (`APP_NAME="Inquiry Handler"`; widget-reference comments → handler) and the matching `APP_NAME` in the local `inquiry-handler/.env`

**Checkpoint**: Foundation ready — the stack exposes `inquiry-handler` on port 8003; user story implementation can begin.

---

## Phase 3: User Story 1 - External Systems Use the Inquiry Handler API (Priority: P1) 🎯 MVP

**Goal**: The triage API remains the primary, supported interface under the renamed service with a byte-for-byte frozen contract (FR-002). External consumers reach `POST /inquiry/triage` on the renamed service exactly as before.

**Independent Test**: `POST http://localhost:8003/inquiry/triage` returns the same request/response shapes and status codes as the pre-rename contract ([contracts/inquiry-web.md](./contracts/inquiry-web.md)), and the service announces itself as Inquiry Handler.

### Implementation for User Story 1

- [X] T006 [US1] Update the API-facing docblock in `inquiry-handler/app/Http/Controllers/InquiryController.php` ("public widget surface" → public triage API + test-console page; GET / renders the test console, POST /inquiry/triage runs triage)
- [X] T007 [P] [US1] Update API-adjacent comments in `inquiry-handler/bootstrap/app.php` ("the widget endpoint" → "the triage API") and `inquiry-handler/config/services.php` ("This widget" → "This handler")
- [X] T008 [US1] Regression-verify the frozen triage contract: run `inquiry-handler/tests/Feature/InquiryTriageTest.php` and `inquiry-handler/tests/Feature/FailurePathsTest.php` and confirm they pass unchanged (no behavior/logic edits allowed)

**Checkpoint**: User Story 1 works independently — the API is contract-identical under the new identity.

---

## Phase 4: User Story 2 - Team Refers to the Service by Its Correct Name (Priority: P1)

**Goal**: Every user- and operator-facing naming surface uses "Inquiry Handler" with zero stale "Inquiry Widget" references (FR-001, SC-1).

**Independent Test**: `rg -i 'widget'` over the service's user/operator-facing files (README, env example, views, routes, compose) returns no matches.

### Implementation for User Story 2

- [X] T009 [P] [US2] Rewrite `inquiry-handler/README.md` — retitle "Inquiry Handler", API-first narrative (API = primary interface), page described as the test console; no "widget" framing
- [X] T010 [P] [US2] Relabel the test page in `inquiry-handler/resources/views/inquiry/index.blade.php` heading/title ("Inquiry Handler — Test Console") and confirm `inquiry-handler/resources/views/layouts/app.blade.php` window title follows `APP_NAME` (form fields and fetch to `/inquiry/triage` unchanged)
- [X] T011 [P] [US2] Update route registration identity in `inquiry-handler/routes/web.php` (comments; route name `widget` → `inquiry.test-console`) — URLs unchanged
- [X] T012 [P] [US2] Update remaining widget references in comments: `inquiry-handler/app/Services/AuthApiClient.php` ("The widget has no human session" → handler), `inquiry-handler/app/Triage/MessageExtractor.php` ("plain-text widget shape" → test-console/handler framing), `inquiry-handler/docker-entrypoint.sh` ("Inquiry widget entrypoint" → "Inquiry Handler entrypoint")
- [X] T013 [US2] Rename-sweep gate: `rg -i 'inquiry.widget|widget'` over user/operator surfaces listed in quickstart scenario 1 → zero matches (spec SC-1); only `specs/004-*` historical docs may retain the old name

**Checkpoint**: User Story 2 works independently — the service is consistently named; US1 + US2 both function.

---

## Phase 5: User Story 3 - Testers Manually Exercise the Flow on a Test Page (Priority: P2)

**Goal**: The relabeled test console renders clearly ("Inquiry Handler — Test Console") and still exercises the triage flow (FR-003, SC-3).

**Independent Test**: Open the page — the test-console heading and message/name/email inputs render; submitting an inquiry shows one of the three dispositions.

### Tests for User Story 3

- [X] T014 [P] [US3] Rename `inquiry-handler/tests/Feature/WidgetPageTest.php` → `inquiry-handler/tests/Feature/TestConsolePageTest.php` and update its assertions to expect the relabeled heading along with the existing message/name/email/submit elements (FR-003, FR-008)
- [X] T015 [US3] Manual validation per quickstart scenario 3: `curl http://localhost:8003/` shows "Test Console" + all inputs, and the form posts to `/inquiry/triage` successfully

**Checkpoint**: User Story 3 works independently; structure of the page is a test aid, not a product surface.

---

## Phase 6: User Story 4 - Triage Behavior Is Unchanged by the Rename (Priority: P3)

**Goal**: Zero behavioral drift — dispositions, failure handling, and health all behave identically (FR-005/FR-006, SC-4/SC-5).

**Independent Test**: The full PHPUnit suite passes green with no assertion count reduction.

### Implementation for User Story 4

- [X] T016 [P] [US4] Update the service-account test username `svc-widget` → `svc-handler` in `inquiry-handler/tests/Unit/ServiceTokenTest.php`
- [X] T017 [US4] Run the full suite green: `cd inquiry-handler && ./vendor/bin/phpunit` (all Feature + Unit tests pass; zero secrets in output)

**Checkpoint**: All four user stories are functional; behavior is provably identical.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Final validation and consistency across the whole rename.

- [X] T018 [P] Run all `specs/005-rename-inquiry-handler/quickstart.md` validation scenarios end-to-end (rename sweep, package/app identity, test console, frozen triage API, validation behavior, full suite, compose health under `inquiry-handler`) and record results
- [X] T019 [P] Security sanity: `rg -i 'api[_-]?key|password|secret' inquiry-handler/ --glob '!vendor/**' --glob '!storage/**'` shows no committed secrets; keys/passwords unchanged by the rename (spec FR-011 carry-over)
- [X] T020 [P] Final docs consistency: confirm all `specs/005-rename-inquiry-handler/` artifacts (spec, plan, research, data-model, contracts, quickstart) are internally consistent and free of stale `inquiry-widget` references (historical `specs/004-*` excluded by policy)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately
- **Foundational (Phase 2)**: Depends on Setup completion (directory must be `inquiry-handler/`) — BLOCKS all user stories
- **User Stories (Phase 3+)**: All depend on Foundational completion
  - US1 and US2 can run after Foundational; US3 and US4 follow independently
- **Polish (Final Phase)**: Depends on all user stories being complete

### User Story Dependencies

- **User Story 1 (P1)**: after Foundational; no other story dependency (frozen API + Identity annotation)
- **User Story 2 (P1)**: after Foundational; no dependency on US1 (different files: README/views/routes/comments vs controller/bootstrap/config)
- **User Story 3 (P2)**: after Foundational; logically follows US2's relabel (view already relabeled) but is independently testable
- **User Story 4 (P3)**: after Foundational; independent verification of zero behavioral drift

### Within Each User Story

- No new models/services/endpoints (rename-only feature) — tasks are identity updates, comment updates, test updates, and regressions; behavior code must NOT be edited

### Parallel Opportunities

- Phase 1: none (T001 must precede; T002/T003 are sequential on composer metadata)
- Phase 3: T007 [P] runs parallel to T006 (different files)
- Phase 4: T009–T012 [P] all run in parallel (README, views, routes, comments — distinct files); T013 sweep after them
- Phase 5: T014 [P] (test file) vs T015 (manual validation) can run in parallel
- Phase 6: T016 [P] (unit test) before T017 (full suite)
- Phase 7: T018–T020 [P] all run in parallel

---

## Parallel Example: User Story 2

```bash
# Launch all User Story 2 file updates together (distinct files):
Task: "Rewrite README in inquiry-handler/README.md"
Task: "Relabel test page heading in inquiry-handler/resources/views/inquiry/index.blade.php"
Task: "Update routes identity in inquiry-handler/routes/web.php"
Task: "Update widget comments in AuthApiClient/MessageExtractor/docker-entrypoint.sh"

# Then run the rename sweep gate:
Task: "Renaming sweep gate (rg -i widget) over user/operator surfaces"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup (directory move + package metadata)
2. Complete Phase 2: Foundational (compose + APP_NAME identity)
3. Complete Phase 3: User Story 1 (frozen API under the renamed service)
4. **STOP and VALIDATE**: `POST /inquiry/triage` returns the identical contract at `localhost:8003`
5. Deploy/demo if ready

### Incremental Delivery

1. Setup + Foundational → renamed, healthy service on port 8003
2. Add User Story 1 → frozen API confirmed under new identity (MVP)
3. Add User Story 2 → consistent naming everywhere (P1, immediate follow-up)
4. Add User Story 3 → relabeled test console verified
5. Add User Story 4 → full suite green, zero behavioral drift
6. Polish → quickstart validated, no secrets, docs consistent

### Parallel Team Strategy

With multiple developers:

1. Developer A: Phase 1 + Phase 2 (move, compose, env)
2. Once done: A → US1 (controller/API annotations + regressions); B → US2 (README/views/routes/comments)
3. C → US3 (test console + test file); D → US4 (service-token test + full suite) after Foundational
4. All converge on Phase 7 polish tasks in parallel

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- Each user story is independently completable and testable
- **Behavioral code must not be edited** — any required logic change is a regression to flag, not fix silently (US4 exists to prove none)
- Paths target the post-move `inquiry-handler/` tree (T001 performs the move); run tests with `cd inquiry-handler && ./vendor/bin/phpunit`
- `specs/004-*` is archival — never edit (user decision, research §4)
- Commit after each task or logical group
- Stop at any checkpoint to validate the story independently