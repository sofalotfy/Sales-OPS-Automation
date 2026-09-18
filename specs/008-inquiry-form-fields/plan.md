# Implementation Plan: Inquiry Form Fields

**Branch**: `008-inquiry-form-fields` | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/008-inquiry-form-fields/spec.md`

## Summary

Replace the current 3-field inquiry form (`message`, `name`, `email`) with a 7-field form (`first_name`, `last_name`, `email`, `phone_number`, `company_name`, `country_region`, `message`) across the existing Laravel inquiry-handler service. First name, last name, email, and message become required; phone number, company name, and country/region are optional. The `name` column is dropped in favor of separate `first_name` / `last_name` columns; existing historical records keep whatever null values the DROP COLUMN produces.

## Technical Context

**Language/Version**: PHP 8.3+

**Primary Dependencies**: Laravel 13 (Blade, Eloquent)

**Storage**: PostgreSQL (inquiry_handler database) — append-only `classification_results` table

**Testing**: PHPUnit 12.5, SQLite in-memory (`RefreshDatabase` trait)

**Target Platform**: Docker Compose network; browser-served test console at `GET /`

**Project Type**: Web-service (API-first with a Blade test console)

**Performance Goals**: Form validation error appears within 1 second (SC-003)

**Constraints**: Existing `POST /inquiry/triage` JSON contract must remain backward-compatible (all existing fields still accepted); contact fields are never sent to the AI (existing behavior); `classification_results` is append-only

**Scale/Scope**: Single Blade form, 5 new columns, ~6 files changed, ~2 new test files

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

**Principle I — Independently Deployable Services**: No new service introduced. The inquiry-handler is modified in place. PASS.

**Principle II — API-First, FastAPI by Default**: The inquiry-handler is a pre-existing Laravel service predating this feature (deployed in Docker Compose since earlier specs 003–007). This feature modifies only an existing service; the FastAPI default applies to **new** services only per the constitution text. No violation.

**Principle III — Human-in-the-Loop for Ambiguity**: Not impacted. Form fields are contact capture; no ambiguity resolution changes. PASS.

**Principle IV — Data Model Is Source of Truth**: New contact fields (`first_name`, `last_name`, `phone_number`, `company_name`, `country_region`) are persisted on `classification_results`, extending the structured state. PASS.

**Principle V — Simplicity and Provisional Scope**: Feature is minimal: field split + add, no speculative generalization. Validation is lenient (no phone format, no country enumeration) per spec assumptions. PASS.

No violations — no Complexity Tracking needed.

## Project Structure

### Documentation (this feature)

```text
specs/008-inquiry-form-fields/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   ├── inquiry-web.md
│   └── classification-reporting.md
└── tasks.md             # Phase 2 output (/speckit.tasks — NOT created here)
```

### Source Code (inquiry-handler service)

```text
inquiry-handler/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── InquiryController.php          # (unchanged)
│   │   └── Middleware/
│   │       └── ScopeGateMiddleware.php         # contact array shape change
│   ├── Models/
│   │   └── ClassificationResult.php           # fillable update
│   ├── Services/
│   │   ├── InquiryTriageService.php           # context/persist changes
│   │   └── ClassificationResultsService.php   # list/detail response update
│   └── Triage/
│       └── MessageExtractor.php              # full rewrite of extract() internals
├── database/
│   └── migrations/
│       └── 2026_09_15_000000_expand_inquiry_form_fields.php   # NEW
├── resources/
│   └── views/
│       └── inquiry/
│           └── index.blade.php               # form + JS update
└── tests/
    ├── Feature/
    │   ├── InquiryTriageTest.php              # update assertions
    │   ├── ClassificationResultsAdminTest.php # update seed + assertions
    │   └── TestConsolePageTest.php            # update field assertions
    └── Unit/
        └── MessageExtractorTest.php           # update + add tests
```

**Structure Decision**: Single-service change inside the existing `inquiry-handler/` Laravel app. No new services or directories introduced.

## Complexity Tracking

No constitution violations — section left empty.
