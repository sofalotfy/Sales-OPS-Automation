# Contract: Inquiry Web (triage endpoint)

**Contract version**: 2.0 (supersedes the 1.x `disposition` contract from specs 004/005)
**Service**: inquiry-handler · **Feature**: [006-weighted-factor-classification](../spec.md)

## `POST /inquiry/triage`

Classifies one sales inquiry with the weighted multi-factor engine.

### Request

```json
{
  "message": "Do you offer annual maintenance contracts for heating boilers?",
  "name": "Jane Doe",
  "email": "jane@example.com"
}
```

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `message` | string | yes | non-blank, ≤ `app.message_max_length` (4000) |
| `name` | string | no | ≤ 255, trimmed |
| `email` | string | no | valid email, ≤ 255 |

Email/name are normalized but never placed in prompt content (unchanged behavior).

### Response `200`

```json
{
  "classification": "high",
  "score": 82.5,
  "factor_scores": {
    "scope_relevance": { "score": 88, "weight": 0.5, "reasoning": "message matches served scope" }
  },
  "dropped_factors": [],
  "reply": "You are in the right place. Pick a time here: https://app.example.com/book",
  "reasoning": "Weighted score 82.5 (>= 75) maps to high.",
  "context": {
    "inquiry": { "message": "...", "name": "Jane Doe", "email": "jane@example.com" },
    "retrieved_context": { "result_count": 1, "results": [ { "...": "RAG result item" } ] }
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `classification` | string | one of `high` \| `medium` \| `low` \| `disqualify` |
| `score` | number | final weighted score 0–100 (0.00 and `low` when catalog is empty) |
| `factor_scores` | object | per contributing factor: `score` (0–100), `weight`, `reasoning` |
| `dropped_factors` | array | factors dropped in this run, with `name` and `reason` |
| `reply` | string | placeholder visitor-facing copy per classification (see scoring config) |
| `reasoning` | string | internal justification |
| `context` | object | preserved normalized inquiry + RAG grounding |

`classification` replaces the v1 `disposition` field (decline/escalate/booking). No `disposition` key is emitted.

### Errors

| Status | Body `detail` | Trigger |
|--------|---------------|---------|
| `400` | `A JSON object is required.` | non-object/malformed JSON body |
| `422` | extraction message (e.g. `The message field is required.`, `The email must be a valid email address.`) | validation violations |
| `503` | `Triage service unavailable. Please try again shortly.` | unrecoverable request-level connection failure |

Upstream factor/AI/retrieval failures never turn into an error response — the degraded classification is returned (FR-007/FR-008).