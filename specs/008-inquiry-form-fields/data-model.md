# Data Model: Inquiry Form Fields

**Feature**: 008-inquiry-form-fields · **Date**: 2026-09-15

## Entity: Classification Result (`classification_results`)

Append-only log. One row per triage run (including scope-gate declines). Existing columns are listed for context; **changed/new columns are bolded**.

### Existing columns (unchanged)

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint (PK) | no | auto-increment |
| `inquiry_message` | text | no | the visitor's message |
| `retrieved_context` | json | no | RAG results at time of triage |
| `factor_scores` | json | no | per-factor score/weight/reasoning |
| `dropped_factors` | json | no | factors that failed and were excluded |
| `final_score` | decimal(5,2) | no | weighted mean score |
| `classification` | varchar(20) | no | high / medium / low / disqualify |
| `reasoning` | text | yes | explanation of classification |
| `scope_check_outcome` | varchar(20) | yes | accept / decline / indeterminate |
| `scope_check_reason` | text | yes | scope gate reasoning |
| `refusal` | text | yes | visitor-facing scope decline message |
| `created_at` | timestamp | yes | row creation time |
| `updated_at` | timestamp | yes | row update time |

### Columns being DROPPED

| Column | Type | Reason |
|---|---|---|
| `name` | varchar(255) | Replaced by `first_name` + `last_name`. Old rows null out on DROP; no migration of existing data. |

### Columns being ADDED

| Column | Type | Nullable | Max Length | Validation |
|---|---|---|---|---|
| `first_name` | varchar(255) | yes* | 255 | required, string, trimmed (application layer) |
| `last_name` | varchar(255) | yes* | 255 | required, string, trimmed (application layer) |
| `email` | varchar(255) | yes* | 255 | required, valid email format, trimmed (application layer) |
| `phone_number` | varchar(255) | yes | 255 | optional; if present, string, trimmed |
| `company_name` | varchar(255) | yes | 255 | optional; if present, string, trimmed |
| `country_region` | varchar(255) | yes | 255 | optional; if present, string, trimmed |

>\* **DB-nullable, application-required.** The table is an append-only production log with historical rows that lack `first_name`/`last_name` (and may lack `email`). A NOT NULL migration on a populated table would fail without backfilling. Per the spec, historical records are not migrated, so:
> - At the **store level**: `first_name`, `last_name`, and `email` stay nullable, and old rows simply carry `null` after the DROP COLUMN of `name`.
> - At the **application level**: `MessageExtractor` rejects any new submission missing `first_name`, `last_name`, or `email` (FR-002), so every new row is populated by construction. The only nullable rows are pre-existing historical records.

### Migration: `2026_09_15_000000_expand_inquiry_form_fields.php`

```sql
-- PostgreSQL (production)
ALTER TABLE classification_results
  DROP COLUMN name;

ALTER TABLE classification_results
  ADD COLUMN first_name varchar(255),
  ADD COLUMN last_name varchar(255),
  ADD COLUMN phone_number varchar(255),
  ADD COLUMN company_name varchar(255),
  ADD COLUMN country_region varchar(255);
```

SQLite (test): `DROP COLUMN` is supported in Laravel 13+ with SQLite 3.35+.

### `down()` reversal

```sql
ALTER TABLE classification_results
  DROP COLUMN first_name,
  DROP COLUMN last_name,
  DROP COLUMN phone_number,
  DROP COLUMN company_name,
  DROP COLUMN country_region;

ALTER TABLE classification_results
  ADD COLUMN name varchar(255);
```

## Inquiry Submission (transient, not persisted as a separate entity)

The inquiry is a transient array produced by `MessageExtractor::extract()`:

```php
[
  'first_name'    => string,      // required, trimmed
  'last_name'     => string,      // required, trimmed
  'email'         => string,      // required, valid email, trimmed
  'phone_number'  => string|null, // optional, trimmed
  'company_name'  => string|null, // optional, trimmed
  'country_region'=> string|null, // optional, trimmed
  'message'       => string,      // required, trimmed, max 4000
]
```

This shape is consumed by:
- `InquiryTriageService::context()` — maps into `context.inquiry` in the response
- `InquiryTriageService::persist()` — maps onto `classification_results` columns
- `ScopeGateMiddleware::declined()` — same persistence path for declined records
