---

description: "Task list for Inquiry Form Fields feature implementation"
---

# Tasks: Inquiry Form Fields

**Input**: Design documents from `/specs/008-inquiry-form-fields/`

**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md, contracts/

**Tests**: Tests are included. This feature modifies an existing service whose test suite is the project's completion bar (`quickstart.md`: "the full suite must stay green"). Existing tests that assert the old field names (`name`, optional `email`) will fail once the extractor/schema change lands, so they MUST be updated within each story phase.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story. This is a modification feature on the existing `inquiry-handler/` Laravel service — no new project scaffolding is needed.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

- Service root: `inquiry-handler/` (Laravel web service; both the API and the Blade test console)
- Tests: `inquiry-handler/tests/`

---

## Phase 1: Setup

**Purpose**: Confirm a clean baseline before any code changes

- [X] T001 Run `php artisan test` in `inquiry-handler/` and confirm the full suite is green before any changes (baseline for regression tracking)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared pieces all three user stories depend on — the column migration and the canonical seven-field validation.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [X] T002 [P] Create migration `2026_09_15_000000_expand_inquiry_form_fields.php` in `inquiry-handler/database/migrations/`: DROP column `name`; ADD nullable `first_name`, `last_name`, `phone_number`, `company_name`, `country_region` (varchar 255) per data-model.md; reversible `down()` restores `name` and drops the five new columns
- [X] T003 [P] Rewrite `MessageExtractor::extract()` in `inquiry-handler/app/Triage/MessageExtractor.php` to return the canonical shape `['first_name', 'last_name', 'email', 'phone_number', 'company_name', 'country_region', 'message']`: `first_name`, `last_name`, `email` required (missing/blank → 422 with `The first name field is required.` / `The last name field is required.` / `The email field is required.`); `email` validated with `FILTER_VALIDATE_EMAIL`; optional fields return `null` when blank, max 255 chars; remove the `name()` method; update the class docblock

**Checkpoint**: Foundation ready — schema has the new columns, and the request shape is canonicalized into the seven fields for all three stories. User story implementation can now begin in parallel.

---

## Phase 3: User Story 1 - Submit Inquiry with Contact Details (Priority: P1) 🎯 MVP

**Goal**: The test-console form shows and submits all seven fields (first name, last name, email, phone number, company name, country/region, message) and the triage response echoes them in `context.inquiry`.

**Independent Test**: Load `GET /` — form renders seven fields (first/last/email/message marked required). Submit all seven via the console (or POST json) → `200` with `low` classification and `context.inquiry` echoing each field. Submit missing `first_name` → `422` with a clear detail. Per `contracts/inquiry-web.md`.

### Tests for User Story 1 ⚠️

> **NOTE: Update these tests FIRST; they fail on the old field names until the implementation below lands**

- [X] T004 [P] [US1] Update `tests/Feature/TestConsolePageTest.php` to assert the form renders First name, Last name, Email, Phone number, Company name, Country/Region, and Message (replace the `Name` assertion)
- [X] T005 [P] [US1] Update `tests/Unit/MessageExtractorTest.php` for the seven-field contract: required first/last/email rejected when missing/blank and max-255 rejected; blank optional fields → null; max-256 optional fields rejected; malformed email rejected; message rules unchanged
- [X] T006 [P] [US1] Update `tests/Feature/InquiryTriageTest.php` request bodies and assertions: send and assert all seven fields; `context.inquiry.name` → `context.inquiry.first_name` / `last_name`; add a missing-`first_name` → 422 case

### Implementation for User Story 1

- [X] T007 [US1] Update the Blade form in `inquiry-handler/resources/views/inquiry/index.blade.php`: replace the single Name input with separate First name and Last name inputs; add Phone number, Company name, Country/Region inputs; mark First name, Last name, Email, Message `required`; keep `maxlength` attributes (255 text fields, 4000 message)
- [X] T008 [US1] Update the inline submit JS in `inquiry-handler/resources/views/inquiry/index.blade.php` to post `{ first_name, last_name, email, phone_number, company_name, country_region, message }` (trimmed) to `/inquiry/triage` — notice the legacy `name` key is removed
- [X] T009 [US1] Update `InquiryTriageService::context()` in `inquiry-handler/app/Services/InquiryTriageService.php` to echo the seven fields in `context.inquiry` (replace `name`/`email`; update the `@param` docblocks referencing the old shape)

**Checkpoint**: US1 complete — form captures and submits all seven fields, validation 422s on missing required fields, and the accepted/indeterminate response echoes them.

---

## Phase 4: User Story 2 - Persist Contact Fields with Inquiry Record (Priority: P2)

**Goal**: Every triage run and scope-gate decline writes the seven fields onto its `classification_results` row; the `name` column is gone.

**Independent Test**: Submit a full inquiry, then inspect the `inquiry_handler` Postgres DB (or SQLite in tests): the latest `classification_results` row carries `first_name`, `last_name`, `email`, `phone_number`, `company_name`, `country_region`, `inquiry_message`; no `name` column remains. Per `contracts/inquiry-web.md` and `data-model.md`.

### Tests for User Story 2 ⚠️

> **NOTE: Update these tests FIRST; they seed/assert the old `name` column and will fail until the implementation below lands**

- [X] T010 [P] [US2] Update persistence assertions in `tests/Feature/InquiryTriageTest.php` (e.g. `test_classification_run_is_persisted_to_the_log`) to assert the new columns
- [X] T011 [P] [US2] Update `tests/Feature/ScopeGateTest.php` so declined/indeterminate records are seeded and asserted with the new contact fields (no `name`)

### Implementation for User Story 2

- [X] T012 [US2] Update `ClassificationResult::fillable` in `inquiry-handler/app/Models/ClassificationResult.php`: remove `name`, add `first_name`, `last_name`, `phone_number`, `company_name`, `country_region`
- [X] T013 [US2] Update `InquiryTriageService::persist()` in `inquiry-handler/app/Services/InquiryTriageService.php` to write the seven fields to `classification_results` (replace the `name`/`email` mapping; update `@param` docblock)
- [X] T014 [US2] Update `ScopeGateMiddleware::declined()` in `inquiry-handler/app/Http/Middleware/ScopeGateMiddleware.php`: persist and echo the new contact fields in `context.inquiry` (replace `name`/`email` in the `ClassificationResult::create()` call, the response `context.inquiry`, and the method docblock)

**Checkpoint**: US2 complete — accepted, indeterminate, and declined runs all persist the seven fields; the old `name` column is dropped.

---

## Phase 5: User Story 3 - View Stored Contact Fields via Admin (Priority: P3)

**Goal**: The admin classification-results API list shows `first_name` / `last_name` / `email`, and the detail response returns all seven contact fields.

**Independent Test**: With a valid bearer token, `GET /admin/classification-results` returns `first_name`, `last_name`, `email` on summary rows; `GET /admin/classification-results/{id}` returns all seven contact fields; no `name` key anywhere. Per `contracts/classification-reporting.md`.

### Tests for User Story 3 ⚠️

> **NOTE: Update these tests FIRST; the seed uses `name` and will fail until the implementation below lands**

- [X] T015 [P] [US3] Update `tests/Feature/ClassificationResultsAdminTest.php`: seed rows with the new contact fields; assert list contains `first_name`/`last_name`/`email`; assert detail returns all seven fields; assert no `name` key

### Implementation for User Story 3

- [X] T016 [US3] Update `ClassificationResultsService::summarize()` in `inquiry-handler/app/Services/ClassificationResultsService.php` to include `first_name`, `last_name`, `email` on list items (phone/company/country stay detail-only)
- [X] T017 [US3] Update `ClassificationResultsService::find()` in `inquiry-handler/app/Services/ClassificationResultsService.php` to return `first_name`, `last_name`, `email`, `phone_number`, `company_name`, `country_region` (replacing `name`)

**Checkpoint**: US3 complete — the dashboard-facing admin API exposes the new contact fields on list and detail; all three stories are independently functional.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Docs, regression suite, and end-to-end validation

- [X] T018 [P] Update `inquiry-handler/README.md` flow description and `POST /inquiry/triage` example to document the seven-field request and the new `context.inquiry` shape (drop the `name`/`email` example)
- [X] T019 Run `php artisan test` in `inquiry-handler/` and confirm the full suite is green
- [ ] T020 Run every scenario in `specs/008-inquiry-form-fields/quickstart.md` (form render, full submission, optional-only submission, required-field 422, admin list/detail, data check) and confirm each expected outcome

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — establishes the green baseline
- **Foundational (Phase 2)**: Depends on Setup; `T002 [P]` migration and `T003 [P]` extractor can run in parallel; BLOCKS all user stories
- **User Stories (Phase 3+)**: All depend on Foundational; may then proceed in parallel (with the file-coordination note below) or sequentially P1 → P2 → P3
- **Polish (Final Phase)**: Depends on all three user stories being complete

### User Story Dependencies

- **User Story 1 (P1)**: After Foundational — no dependencies on other stories. Independently testable at `GET /`
- **User Story 2 (P2)**: After Foundational — independently testable via DB row inspection; no dependency on US1 **except** the `T003` extractor shape (foundational), so fully parallel with US1 is possible
- **User Story 3 (P3)**: After Foundational — independently testable (admin tests seed rows directly); no dependency on US1 or US2
- ⚠️ **Shared file coordination**: US1 `T009` and US2 `T013` both edit `inquiry-handler/app/Services/InquiryTriageService.php`. If run in parallel by different people, sequence them or merge in a single commit to avoid conflict. `T010` and `T013` also both touch `tests/Feature/InquiryTriageTest.php` in a cross-story way (US1 updates its request/context assertions, US2 updates its persistence assertions) — coordinate if parallel.

### Within Each User Story

- Tests MUST be updated to the new contract and FAIL before the implementation lands
- Extract/validate (foundational) → response/context → persistence → reporting
- Story complete before moving to next priority (or before checking the phase checkpoint)

### Parallel Opportunities

- `T002` and `T003` (foundational) can run in parallel
- `T004`, `T005`, `T006` (US1 tests) can run in parallel
- `T010` and `T011` (US2 tests) can run in parallel
- US1, US2, US3 implementations are otherwise on distinct files and can proceed in parallel, barring the two `InquiryTriageService.php`/`InquiryTriageTest.php` coordination notes above
- `T018`, `T019`, `T020` (polish) can run in parallel

---

## Parallel Example: Foundational

```bash
# Launch the two foundational tasks together:
Task: "Create migration ...expand_inquiry_form_fields.php in inquiry-handler/database/migrations/"
Task: "Rewrite MessageExtractor::extract() in inquiry-handler/app/Triage/MessageExtractor.php"
```

## Parallel Example: User Story 1

```bash
# Launch all US1 test updates together:
Task: "Update tests/Feature/TestConsolePageTest.php"
Task: "Update tests/Unit/MessageExtractorTest.php"
Task: "Update tests/Feature/InquiryTriageTest.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup (baseline green)
2. Complete Phase 2: Foundational — migration + `MessageExtractor`
3. Complete Phase 3: User Story 1 — form, JS payload, `context.inquiry` echo
4. **STOP and VALIDATE**: run the updated US1 tests; confirm the console accepts seven fields and 422s without required ones
5. Deploy/demo if ready (note: rows written before US2 will carry null contact columns)

### Incremental Delivery

1. Setup + Foundational → Foundation ready (schema has new columns, extractor canonicalizes seven fields)
2. User Story 1 → form + validation + response echo works → Deploy/Demo (MVP)
3. User Story 2 → all runs (incl. scope declines) persist the seven fields → Deploy/Demo
4. User Story 3 → admin list/detail expose contact fields → Deploy
5. Polish → docs, full suite green, quickstart validated

### Parallel Team Strategy

With multiple developers:

1. Foundation together: one dev on the migration, one on `MessageExtractor`
2. Once foundation is done:
   - Developer A: User Story 1 (form + context echo)
   - Developer B: User Story 2 (persistence) — await/coordinate on `InquiryTriageService.php` and `InquiryTriageTest.php`
   - Developer C: User Story 3 (admin reporting)
3. Stories complete and integrate independently

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to a specific user story for traceability
- Each user story is independently completable and testable; US3's tests seed rows directly so it never needs a running triage flow
- The feature maintains the existing project conventions: append-only `classification_results`, contacts never sent to the AI, tests on SQLite `:memory:` via `RefreshDatabase`
- Commit after each task or logical group; verify tests fail before implementing