# Quickstart: Validate Inquiry Form Fields

**Feature**: 008-inquiry-form-fields · **Date**: 2026-09-15

This guide proves the 7-field form works end-to-end. It references the contracts and data model instead of duplicating them.

## Prerequisites

- Project stack running: `docker compose up --build -d` (brings up `inquiry-handler` at `http://localhost:8003`, fully migrated)
- OR local development with `composer install` inside `inquiry-handler/`

## Automated validation (SQLite in-memory)

The full suite must stay green; requires no running stack:

```sh
cd inquiry-handler
php artisan test
```

Expected outcomes:

| Suite / test | Verifies |
|---|---|
| `tests/Unit/MessageExtractorTest.php` | new required fields (`first_name`, `last_name`, `email`) rejected when missing/blank; optional fields (`phone_number`, `company_name`, `country_region`) return null when empty; max-length 255 enforced; email format validated |
| `tests/Feature/TestConsolePageTest.php` | the test-console page renders all seven form fields |
| `tests/Feature/InquiryTriageTest.php` | `POST /inquiry/triage` with all seven fields returns `low` and echoes the contact fields in `context.inquiry`; missing key field → 422; persisted row contains the new columns |
| `tests/Feature/ClassificationResultsAdminTest.php` | admin list shows `first_name` / `last_name` / `email`; admin detail returns all seven contact fields; `name` is absent |
| `tests/Feature/ScopeGateTest.php` | declined record persists the new contact fields (regression) |

## Manual validation (running stack)

### 1. Form renders with all fields

1. Open `http://localhost:8003/`
2. Confirm the form shows **7** inputs: First name, Last name, Email, Phone number, Company name, Country/Region, and Message.
3. Confirm `First name`, `Last name`, `Email`, and `Message` are marked required.

### 2. Full submission succeeds

1. Fill in all seven fields, e.g.:
   - First name: `Jane`
   - Last name: `Doe`
   - Email: `jane@example.com`
   - Phone number: `+1 555 0132`
   - Company name: `Example Corp`
   - Country/Region: `United Kingdom`
   - Message: `Do you offer annual maintenance contracts for heating boilers?`
2. Click **Send inquiry**.
3. Expected: a classification `low` renders (empty factor catalog — see [contract](contracts/inquiry-web.md)). No validation error.
4. (Optional) Submit the same inquiry via `curl` and confirm `context.inquiry` echoes all seven fields:

```sh
curl -s -X POST http://localhost:8003/inquiry/triage \
  -H 'Content-Type: application/json' \
  -d '{"first_name":"Jane","last_name":"Doe","email":"jane@example.com","phone_number":"+1 555 0132","company_name":"Example Corp","country_region":"United Kingdom","message":"Do you offer annual maintenance contracts for heating boilers?"}'
```

### 3. Optional fields are truly optional

1. Submit with only `first_name`, `last_name`, `email`, and `message`.
2. Expected: submission succeeds; `context.inquiry.phone_number`, `company_name`, and `country_region` are `null`.

### 4. Required-field validation

1. Clear the message (or first name) and submit.
2. Expected: an error appears quickly (SC-003: within ~1 second), e.g. `The first name field is required.` (422).

### 5. Admin reporting shows contact fields

1. Obtain a valid bearer token (from `auth-service`), then:

```sh
curl -s http://localhost:8003/admin/classification-results \
  -H 'Authorization: Bearer <token>'
curl -s http://localhost:8003/admin/classification-results/<id> \
  -H 'Authorization: Bearer <token>'
```

2. Expected (per [classification-reporting contract](contracts/classification-reporting.md)):
   - List items include `first_name`, `last_name`, `email`.
   - Detail includes `first_name`, `last_name`, `email`, `phone_number`, `company_name`, `country_region`.
   - No `name` key anywhere.

## Data verification (optional)

In the `inquiry_handler` Postgres database, after a full submission:

```sql
SELECT first_name, last_name, email, phone_number, company_name, country_region, inquiry_message
FROM classification_results
ORDER BY id DESC LIMIT 1;
```

Expected: one row carrying all seven values; the `name` column no longer exists (see [data-model.md](data-model.md)).

## Regression: legacy behavior still covered

- A submission with the old `name` key is ignored (no crash); the request succeeds only if the new required fields are present.
- `GET /health` still returns `{"status": "ok"}`.
- The scope gate (feature 007) still runs before classification when enabled and persists the new contact fields on decline.