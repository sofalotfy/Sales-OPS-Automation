# Research: Inquiry Form Fields

**Feature**: 008-inquiry-form-fields · **Date**: 2026-09-15

No unresolved clarifications. All design decisions below resolve open questions the spec deliberately left to reasonable defaults.

---

## R1 — Migration strategy for the `name` → `first_name` / `last_name` split

**Decision**: DROP the existing `name` column in a single migration, and ADD `first_name`, `last_name`, `phone_number`, `company_name`, `country_region` as nullable columns.

**Rationale**: The spec explicitly states "there is no backward-compatibility requirement for the old field name in new submissions." The `classification_results` table is append-only, so old rows with a `name` value will lose that value on DROP. This is acceptable because:
- Historical records remain accessible by their other fields (`inquiry_message`, `classification`, `created_at`).
- The admin detail endpoint will simply return `null` for old records' `first_name` / `last_name`.
- The alternative (keeping the `name` column alongside the new columns) adds schema clutter with no consumer.

**Alternatives considered**:
- Keep `name` alongside new columns: Rejected; creates a dead column with no consumer, adding confusion.
- Migrate `name` data to `first_name` (best-effort string split): Rejected; unreliable heuristic for arbitrary name formats (e.g., "de la Cruz", single names, prefixes). Not worth the complexity for historical audit data.

---

## R2 — Required vs optional field assignment

**Decision**: `first_name` (required), `last_name` (required), `email` (required), `message` (required); `phone_number`, `company_name`, `country_region` (all optional, nullable).

**Rationale**: The spec mandates first name, last name, email, and message. Phone/company/country are explicitly optional per spec FR-003. The current `email` is already optional in the codebase; making it required is a spec-driven change that the Blade form must enforce via `required` attributes and the `MessageExtractor` via a new `requiredString` validation helper.

**Alternatives considered**:
- Keep `email` optional (status quo): Rejected; spec FR-002 is explicit that email is required.
- Make all contact fields required: Rejected; spec FR-003 explicitly marks phone/company/country as optional.

---

## R3 — Phone number validation approach

**Decision**: Accept any non-empty string up to 255 characters. No format/pattern validation.

**Rationale**: The spec's Assumptions section explicitly states "Phone number validation is intentionally lenient — the system accepts any string up to 255 characters rather than enforcing a specific phone format, to accommodate international numbers." International phone formats (E.164, national, with extensions) are too varied for a basic regex without a dedicated library, which would be over-engineering for this scope.

**Alternatives considered**:
- E.164 regex validation: Rejected; excludes common user input patterns (e.g., "+44 20 7946 0958", "ext. 123"), violates spec intent.
- Phone library (libphonenumber): Rejected; heavy dependency for a lenient validation requirement.

---

## R4 — Country/region as free-text

**Decision**: Free-text string input, max 255 characters, no enum or dropdown.

**Rationale**: The spec states "The country/region field is free-text, not a dropdown or validated against a list of countries." A dropdown would require maintaining a country list (ICU, ISO 3166), adding scope and maintenance burden inconsistent with the provisional scope principle.

**Alternatives considered**:
- Country dropdown with ISO 3166 list: Rejected per spec assumptions; premature optimization for an unconfirmed sales team need.

---

## R5 — ScopeGateMiddleware contact field propagation

**Decision**: The `ScopeGateMiddleware::handle()` extracts the new fields (`first_name`, `last_name`, `email`) and passes them to `ScopeCheckService::check()`. `ScopeCheckService` already only uses `$inquiry['message']` for retrieval and prompt building, so no AI prompt changes are needed. Contact fields continue to flow through untouched for persistence only.

**Rationale**: The contact fields were already extracted and passed through in the current code; only the key names change. No behavioral impact on scope-gate logic.

**Alternatives considered**:
- Stop extracting contacts in the middleware, extract only in the controller: Rejected; breaks the declined-record persistence path which needs contact fields to write the row.

---

## R6 — Admin summary display of new fields

**Decision**: Add `first_name` and `last_name` (and optionally `email`) to the admin list summary; all seven fields on the detail endpoint.

**Rationale**: The spec requires the admin list to show first name, last name, and email (US3, acceptance scenario 1). The detail page shows all contact fields (US3, acceptance scenario 2). The list omits phone/company/country to keep the summary compact, matching the existing pattern of omitting heavy payloads from the list view.

**Alternatives considered**:
- Show all seven fields in the list: Rejected; list rows would become too wide and violate the existing "summary omits heavy/verbose payloads" convention.
