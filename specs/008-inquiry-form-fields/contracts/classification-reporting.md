# Contract: Classification-Results Admin API — contact field display

**Contract version**: 1.1 · **Feature**: [008-inquiry-form-fields](../spec.md)
**Base contract**: [specs/006-weighted-factor-classification/contracts/classification-reporting.md](../../006-weighted-factor-classification/contracts/classification-reporting.md)

Additive change to the read-only admin reporting API: the detail response replaces `name` with `first_name` / `last_name` and adds `phone_number`, `company_name`, `country_region`. The list response adds `first_name`, `last_name`, and `email`. Authentication, pagination, truncation, and error behavior are unchanged.

## List: `GET /admin/classification-results`

Summary rows gain the primary contact identifiers; phone/company/country remain detail-only:

```json
{
  "items": [
    {
      "id": 41,
      "classification": "low",
      "final_score": 0.0,
      "inquiry_message": "Do you offer annual maintenance…",
      "first_name": "Jane",
      "last_name": "Doe",
      "email": "jane@example.com",
      "reasoning": "No factors are registered; catalog is empty.",
      "created_at": "2026-09-14T10:00:00+00:00"
    }
  ],
  "total": 41,
  "limit": 20,
  "offset": 0
}
```

## Detail: `GET /admin/classification-results/{id}`

The full immutable run:

```json
{
  "id": 41,
  "inquiry_message": "Do you offer annual maintenance contracts?",
  "first_name": "Jane",
  "last_name": "Doe",
  "email": "jane@example.com",
  "phone_number": "+1 555 0132",
  "company_name": "Example Corp",
  "country_region": "United Kingdom",
  "classification": "low",
  "final_score": 0.0,
  "reasoning": "No factors are registered; catalog is empty.",
  "factor_scores": [],
  "dropped_factors": [],
  "retrieved_context": { "result_count": 5, "results": [] },
  "system_prompt": "You are the scope-judgment gate for a sales team. … SERVED SCOPE …",
  "created_at": "2026-09-14T10:00:00+00:00"
}
```

> **Historical rows**: records created before this feature return `first_name`, `last_name` (and possibly `email`) as `null`. Consumers must treat these as nullable.
>
> **System prompt**: RAG context retrieval is no longer used; the response now also carries `system_prompt` — the fixed classification prompt (company/scope context) each inquiry was judged against. Records created before this field was added return `system_prompt` as `null`.

## Errors

| status | body |
|---|---|
| `401` | `{"detail": "Not authenticated."}` |
| `404` | `{"detail": "Classification result not found."}` |
| `503` | `{"detail": "Classification log unavailable."}` |