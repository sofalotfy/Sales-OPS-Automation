# Contract: Classification-Results Admin API — research agent payload

**Contract version**: 1.4 · **Feature**: [010-ai-research-agent](../spec.md)
**Base contract**: [specs/008-inquiry-form-fields/contracts/classification-reporting.md](../../008-inquiry-form-fields/contracts/classification-reporting.md)

Additive change to the read-only admin reporting API: the detail response's
`web_research` payload now carries the AI research agent's summary instead of the
raw `company`/`person` result lists, and raw candidates are no longer present.
Authentication, pagination, truncation, list fields, and error behavior are
unchanged.

## List: `GET /admin/classification-results`

Unchanged. Each item still carries `web_research_outcome` (the step outcome); the
heavy `web_research` payload remains detail-only.

## Detail: `GET /admin/classification-results/{id}`

```json
{
  "id": 41,
  "inquiry_message": "Do you build enterprise web applications?",
  "first_name": "Jane",
  "last_name": "Doe",
  "email": "jane@example.com",
  "phone_number": "+1 555 0132",
  "company_name": "Example Corp",
  "country_region": "United Kingdom",
  "classification": "high",
  "final_score": 85.0,
  "reasoning": "Weighted score 85.0 (>= 75) maps to high.",
  "factor_scores": {
    "company_size": { "score": 85, "weight": 1, "reasoning": "About 5,000 staff and a global footprint place this in the large tier." }
  },
  "dropped_factors": [],
  "retrieved_context": { "result_count": 0, "results": [] },
  "scope_check_outcome": "accept",
  "scope_check_reason": "The inquiry is within the company's served scope.",
  "refusal": null,
  "web_research_outcome": "accept",
  "web_research_reason": "Web research completed.",
  "web_research": {
    "criteria": {
      "company": { "name": "Example Corp", "country_region": "United Kingdom" },
      "person":  { "first_name": "Jane", "last_name": "Doe", "company_context": "Example Corp" }
    },
    "findings": {
      "outcome": "completed",
      "summary": "Example Corp is a UK fintech; Jane Doe leads engineering there …",
      "sources": [ { "title": "Example Corp — About", "url": "https://example.com/about" } ],
      "limitations": null,
      "uncertain": false
    },
    "audit": {
      "counts": { "obtained": 12, "kept": 2, "rejected": 10 },
      "kept":   [ { "title": "Example Corp — About", "url": "https://example.com/about" } ],
      "rejected": [ { "title": "10 Best Companies to Work For", "url": "https://aggregator.example/list" } ],
      "filter_outcome": "ok",
      "filter_keep": [1, 5],
      "filter_reason": "Matches the target entity.",
      "rescue_used": false
    }
  },
  "system_prompt": "You are the scope-judgment gate for a sales team. … SERVED SCOPE …",
  "created_at": "2026-09-14T10:00:00+00:00"
}
```

`factor_scores` is a map keyed by the registered factor name (identical to the
triage response, `contracts/inquiry-web.md`); each value carries the stored
`{score, weight, reasoning}`. Rows created while the catalog was empty store
`factor_scores: {}`. A `company_size` with score `0` is an honest "no size
signal" (feature 009) and, being the only registered factor at default weight,
maps the run to `disqualify`.

> **Changed shape**: `web_research.findings` is the research agent result
> (`outcome`, `summary`, `sources`, `limitations`, and `uncertain` — the last
> added 1.3, `true` only for best-effort rescued profiles) — see
> [data-model.md](../data-model.md). The raw `findings.company` /
> `findings.person` result lists from records created before this feature are
> **not** returned in the new shape; historical rows keep whatever was stored at
> the time (consumers must treat `findings` as version-tolerant).

> **Indeterminate rows**: when the step failed open, `web_research_outcome` is
> `indeterminate` and `web_research.findings` is empty, exactly as before.

> **Candidate audit (added 1.4)**: when a run gathered at least one candidate,
> the detail response also carries `web_research.audit` — the `{counts,
> kept, rejected, filter_outcome, filter_keep, filter_reason, rescue_used}`
> snapshot the dashboard renders to verify filtering. Runs with no candidates
> or indeterminate failures produce `audit: []`. The audit is only present for
> records created after 1.4; older rows simply omit the key.

## Errors

| status | body |
|---|---|
| `401` | `{"detail": "Not authenticated."}` |
| `404` | `{"detail": "Classification result not found."}` |
| `503` | `{"detail": "Classification log unavailable."}` |
