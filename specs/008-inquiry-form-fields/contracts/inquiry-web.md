# Contract: Inquiry Web (triage endpoint) — form fields

**Contract version**: 2.2 · **Service**: inquiry-handler · **Feature**: [008-inquiry-form-fields](../spec.md)
**Base contract** (unchanged parts): [specs/007-scope-gate-middleware/contracts/inquiry-web.md](../../007-scope-gate-middleware/contracts/inquiry-web.md)

This feature expands the inquiry request to seven fields and changes the `name` field to `first_name` + `last_name`.

## `POST /inquiry/triage`

### Request

```json
{
  "first_name": "Jane",
  "last_name": "Doe",
  "email": "jane@example.com",
  "phone_number": "+1 555 0132",
  "company_name": "Example Corp",
  "country_region": "United Kingdom",
  "message": "Do you offer annual maintenance contracts for heating boilers?"
}
```

Field validation (enforced by `MessageExtractor.extract()`):

| Field | Required | Type | Rules |
|---|---|---|---|
| `first_name` | **yes** | string | non-blank after trim, max 255 chars |
| `last_name` | **yes** | string | non-blank after trim, max 255 chars |
| `email` | **yes** | string | valid email format (`FILTER_VALIDATE_EMAIL`), max 255 chars |
| `phone_number` | no | string | if present: non-blank after trim, max 255 chars |
| `company_name` | no | string | if present: non-blank after trim, max 255 chars |
| `country_region` | no | string | if present: non-blank after trim, max 255 chars |
| `message` | **yes** | string | non-blank after trim, max 4000 chars |

> **Backward compatibility**: The legacy `name` key is no longer accepted. Any request body still sending `name` will have it ignored; the request fails only if it is also missing the now-required `first_name` / `last_name` / `email`. No response-shape change beyond the renamed contact fields — consumers see `context.inquiry.name` replaced by `context.inquiry.first_name` / `context.inquiry.last_name`.

### Response `200` — accepted / indeterminate inquiries

Everything matches contract 2.1 except `context.inquiry`:

```json
{
  "classification": "high",
  "score": 82.5,
  "factor_scores": { "scope_relevance": { "score": 88, "weight": 0.5, "reasoning": "..." } },
  "dropped_factors": [],
  "reply": "You are in the right place. Pick a time here: https://app.example.com/book",
  "reasoning": "Weighted score 82.5 (>= 75) maps to high.",
  "context": {
    "inquiry": {
      "first_name": "Jane",
      "last_name": "Doe",
      "email": "jane@example.com",
      "phone_number": "+1 555 0132",
      "company_name": "Example Corp",
      "country_region": "United Kingdom",
      "message": "Do you offer annual maintenance contracts for heating boilers?"
    },
    "retrieved_context": { "result_count": 0, "results": [] },
    "system_prompt": "You are the scope-judgment gate for a sales team. … SERVED SCOPE …",
    "scope_check": { "outcome": "accept", "reason": "message matches served scope" }
  }
}
```

### Response `200` — declined inquiry

Same shape as 2.1; `context.inquiry` uses the new contact fields:

```json
{
  "classification": "disqualify",
  "score": 0.0,
  "factor_scores": {},
  "dropped_factors": [],
  "reply": "We only build enterprise web applications and sales/CRM tooling, so we can't help with appliance repair.",
  "reasoning": "Inquiry is outside the served scope per retrieved knowledge.",
  "context": {
    "inquiry": {
      "first_name": "Jane",
      "last_name": "Doe",
      "email": "jane@example.com",
      "phone_number": "+1 555 0132",
      "company_name": "Example Corp",
      "country_region": "United Kingdom",
      "message": "Do you offer annual maintenance contracts for heating boilers?"
    },
    "retrieved_context": { "result_count": 0, "results": [] },
    "system_prompt": "You are the scope-judgment gate for a sales team. … SERVED SCOPE …",
    "scope_check": { "outcome": "decline", "reason": "Inquiry is outside the served scope per retrieved knowledge." }
  }
}
```

### Errors — unchanged (2.0/2.1)

| Status | Body `detail` | Trigger |
|--------|---------------|---------|
| `400` | `A JSON object is required.` | non-object/malformed JSON body |
| `422` | extraction message | validation violations (missing/blank/invalid/max-length) |
| `503` | `Triage service unavailable. Please try again shortly.` | unrecoverable request-level connection failure in the classification flow |

New 422 messages introduced by this feature: `The first name field is required.`, `The last name field is required.`, `The email field is required.` (all returned when the field is missing/blank).

### Persistence

Every row on `classification_results` now stores `first_name`, `last_name`, `phone_number`, `company_name`, `country_region`, and `email`; the `name` column is dropped. See [data-model.md](../data-model.md).