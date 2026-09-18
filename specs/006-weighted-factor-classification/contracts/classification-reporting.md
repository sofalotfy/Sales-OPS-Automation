# Contract: Classification-Results Admin API

**Feature**: [006-weighted-factor-classification](../spec.md) · Consumed by the dashboard **Inquiry classification** tab (list + detail).

Read-only admin reporting over the append-only classification log. Requests are authenticated exactly like [factor-settings-admin.md](factor-settings-admin.md): the caller presents `Authorization: Bearer <auth-service token>`, which inquiry-handler verifies against auth-service `GET /auth/verify`. The payloads are served over HTTP only — the dashboard never connects to the `inquiry_handler` database (SC-006).

## List: `GET /admin/classification-results`

Optional query parameters:

| param | default | rule |
|---|---|---|
| `limit` | `20` | clamped to `1–50` |
| `offset` | `0` | `>= 0` |

Response — newest-first page in the stack's established pagination shape (mirrors `document-api.md` `/documents`):

```json
{
  "items": [
    {
      "id": 41,
      "classification": "low",
      "final_score": 0.0,
      "inquiry_message": "Do you offer annual maintenance…",
      "reasoning": "No factors are registered; catalog is empty.",
      "created_at": "2026-09-14T10:00:00+00:00"
    }
  ],
  "total": 41,
  "limit": 20,
  "offset": 0
}
```

Summary items deliberately omit the heavy `factor_scores` / `retrieved_context` payloads; fetch them per-row via the detail endpoint (`inquiry_message` and `reasoning` are truncated server-side).

## Detail: `GET /admin/classification-results/{id}`

Response — the full immutable run (same field names as the stored row in [../data-model.md](../data-model.md)):

```json
{
  "id": 41,
  "inquiry_message": "Do you offer annual maintenance contracts?",
  "name": null,
  "email": null,
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

## Errors

| status | body |
|---|---|
| `401` | `{"detail": "Not authenticated."}` (missing/invalid/unverifiable token; identical to factor-settings) |
| `404` | `{"detail": "Classification result not found."}` (unknown `id`) |
| `503` | `{"detail": "Classification log unavailable."}` (scoped store unreadable) |