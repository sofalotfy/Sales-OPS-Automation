# Contract: Inquiry Web (triage endpoint) — Scope Gate additions

**Contract version**: 2.1 · **Service**: inquiry-handler · **Feature**: [007-scope-gate-middleware](../spec.md)
**Base contract** (unchanged parts): [specs/006-weighted-factor-classification/contracts/inquiry-web.md](../../006-weighted-factor-classification/contracts/inquiry-web.md)

This feature places a scope gate in front of `POST /inquiry/triage`. The **request** contract, validation, and **error** behavior are unchanged. The **200 response** gains a `context.scope_check` object for every screened inquiry, and a **declined** inquiry returns a response in the same top-level shape (no new shape, no new status — Clarification Q3 / FR-006).

## `POST /inquiry/triage`

### Request — unchanged (2.0)

```json
{
  "message": "Do you offer annual maintenance contracts?",
  "name": "Jane Doe",
  "email": "jane@example.com"
}
```

Field types/required/validation identical to contract 2.0.

### Response `200` — accepted / indeterminate inquiries (2.0 shape + `scope_check`)

The gate verdict rides inside `context`; everything else is the feature-006 response:

```json
{
  "classification": "high",
  "score": 82.5,
  "factor_scores": { "scope_relevance": { "score": 88, "weight": 0.5, "reasoning": "..." } },
  "dropped_factors": [],
  "reply": "You are in the right place. Pick a time here: https://app.example.com/book",
  "reasoning": "Weighted score 82.5 (>= 75) maps to high.",
  "context": {
    "inquiry": { "message": "...", "name": "Jane Doe", "email": "jane@example.com" },
    "retrieved_context": { "result_count": 1, "results": [ { "...": "RAG result item" } ] },
    "scope_check": { "outcome": "accept", "reason": "message matches served scope" }
  }
}
```

`context.scope_check.outcome` is `accept` (in scope) or `indeterminate` (grounding/AI unavailable), always alongside the gate's `reason`. All other fields as 2.0.

### Response `200` — declined inquiry

The gate short-circuits **before** classification (FR-006, SC-001). Same top-level shape; `classification` is fixed to `disqualify`, scoring fields are empty, and the visitor-facing message is the refusal reason:

```json
{
  "classification": "disqualify",
  "score": 0.0,
  "factor_scores": {},
  "dropped_factors": [],
  "reply": "We only build enterprise web applications and sales/CRM tooling, so we can't help with appliance repair.",
  "reasoning": "Inquiry is outside the served scope per retrieved knowledge.",
  "context": {
    "inquiry": { "message": "...", "name": "Jane Doe", "email": "jane@example.com" },
    "retrieved_context": { "result_count": 1, "results": [ { "...": "RAG result item" } ] },
    "scope_check": { "outcome": "decline", "reason": "Inquiry is outside the served scope per retrieved knowledge." }
  }
}
```

- `reply` carries the **refusal text** the visitor sees (non-technical, grounded in the company's scope).
- `context.scope_check.outcome = "decline"` is the machine marker that the inquiry was refused (FR-006). The widget renders this the same as any other `reply` — no client change.

### Scope-check outcomes (FR-004/FR-007/FR-012)

| Outcome | Meaning | Flow | Stored on record |
|---------|---------|------|------------------|
| `accept` | in scope | gate passes through → full classification runs | `scope_check_outcome='accept'` |
| `decline` | out of scope | gate stops → persists refusal → returns response above | `scope_check_outcome='decline'`, `refusal`, `scope_check_reason` |
| `indeterminate` | cannot determine (retrieval/AI unavailable, no relevant documents, unparseable AI output) | gate fails open → full classification runs; never fabricated, never dropped | `scope_check_outcome='indeterminate'` |

### Errors — unchanged (2.0)

| Status | Body `detail` | Trigger |
|--------|---------------|---------|
| `400` | `A JSON object is required.` | non-object/malformed JSON body (before the gate) |
| `422` | extraction message | validation violations (before the gate) |
| `503` | `Triage service unavailable. Please try again shortly.` | unrecoverable request-level connection failure in the classification flow |

The gate itself never produces an error response: unparseable/invalid payloads fall through to the existing errors, and upstream failures resolve to `indeterminate` (fail open).

### Persistence (SC-006/SC-007)

Every screened inquiry records its scope-check outcome on its `classification_results` row (`scope_check_outcome` / `scope_check_reason` / `refusal`) — see [data-model.md](../data-model.md). Declined rows are inserted by the gate; accepted/indeterminate rows by the classification flow.