# Contract: Inquiry Web (triage endpoint) — AI research agent findings

**Contract version**: 2.4 · **Service**: inquiry-handler · **Feature**: [010-ai-research-agent](../spec.md)
**Base contract** (unchanged parts): [specs/008-inquiry-form-fields/contracts/inquiry-web.md](../../008-inquiry-form-fields/contracts/inquiry-web.md)

This feature replaces the **raw** web-research findings with the AI research
agent's filtered, fetched, and summarized result. The request, validation,
top-level response shape, decline shape, and error behavior are unchanged. Only
the contents of `context.web_research.findings` change, and raw candidate result
lists are no longer returned (spec Clarification Q1:C).

## `POST /inquiry/triage`

### Request — unchanged (2.2)

Same seven fields and validation as contract 2.2.

### Response `200` — accepted / indeterminate inquiries (2.2 shape + new findings)

```json
{
  "classification": "high",
  "score": 85.0,
  "factor_scores": {
    "company_size": { "score": 85, "weight": 1, "reasoning": "About 5,000 staff and a global footprint place this in the large tier." }
  },
  "dropped_factors": [],
  "reply": "You are in the right place. Pick a time here: https://app.example.com/book",
  "reasoning": "Weighted score 85.0 (>= 75) maps to high.",
  "context": {
    "inquiry": { "first_name": "Jane", "last_name": "Doe", "email": "jane@example.com",
                 "phone_number": "+1 555 0132", "company_name": "Example Corp",
                 "country_region": "United Kingdom", "message": "…" },
    "retrieved_context": { "result_count": 0, "results": [] },
    "system_prompt": "You are the scope-judgment gate for a sales team. …",
    "web_research": {
      "outcome": "accept",
      "reason": "Web research completed.",
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
      }
    }
  }
}
```

`factor_scores` lists every code-registered factor that produced a verdict. As of
this contract the single registered factor is `company_size` (feature
[009-company-size-factor](../../009-company-size-factor/spec.md)): an AI-call
score over the web-research findings, anchored as a 0–100 number with an honest
reasoning string. A score of `0` is a truthful "no size signal" (blank/missing
company name, `not_found`/`ambiguous` findings, unavailable research, AI failure,
or out-of-range AI output) — never a fabricated estimate. Because `company_size`
carries the default weight `1`, weighted `0.00` maps below the `low` band (30) and
classifies as `disqualify`; consumers must not treat a zero score as an active
disqualification by the factor itself.

### Changed field: `context.web_research.findings`

| Before (≤ 2.2) | After (2.3 → 2.4) |
|---|---|
| `{ company: { query, summary, found, results: [...] }, person: { … } }` | `{ outcome, summary, sources: [{title,url}], limitations, uncertain }` |

- `findings.outcome` ∈ `completed | partial | not_found | ambiguous`.
- `findings.summary` is a visitor-safe, source-grounded profile, or an explicit
  statement when the entity is not found / ambiguous.
- `findings.sources` lists only URLs that were actually fetched and used.
- `findings.uncertain` (added 2.4) is `true` only when the profile came from the
  best-effort name-match rescue (few/confounded sources); consumers should show
  it as "verify before relying".
- Raw candidate `results[]` are **not** returned (nor stored).

### Outcome semantics

| `context.web_research.outcome` | `findings.outcome` | Meaning |
|---|---|---|
| `accept` | `completed` | on-entity summary produced from fetched sources |
| `accept` | `partial` | summary produced, but some sources failed to fetch **or** the profile came from the best-effort rescue (`uncertain: true`) |
| `accept` | `not_found` | no candidate was about the named entity (honest statement, no fabrication) |
| `accept` | `ambiguous` | name matches multiple distinct entities (honest statement) |
| `decline` | *(unchanged)* | provider short-circuit refusal — unchanged shape/behavior |
| `indeterminate` | *(empty)* | provider/AI/fetch failure — fail open, classification still runs |

### Response `200` — declined inquiry

Unchanged from contract 2.2 apart from the findings shape on the recorded
`web_research` payload; the refusal still comes from the provider `decline` and
the response stays `{classification: 'disqualify', score: 0.0, factor_scores: {},
dropped_factors: [], reply: <refusal>, reasoning: <reason>, context: {…}}`.

### Errors — unchanged (2.2)

`400` non-object JSON, `422` validation, `503` unrecoverable connection failure.
The research agent never produces an error response; failures resolve to
`indeterminate` (fail-open).

### Persistence (updated)

Every enriched row stores the new `web_research` shape on
`classification_results.web_research`; `web_research_outcome` /
`web_research_reason` are unchanged. No migration — see
[data-model.md](../data-model.md).
