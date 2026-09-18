# Contract: Factor Settings Admin API (inquiry-handler)

**Contract version**: 1.0
**Service**: inquiry-handler · **Feature**: [006-weighted-factor-classification](../spec.md)
**Consumed by**: dashboard `InquiryHandlerApiClient` (admin tab) · **Policies**: [research R6](../research.md)

Base URL: `http://inquiry-handler:8003` (Compose DNS) / `http://localhost:8003` (host).

## Authentication

Both endpoints require `Authorization: Bearer <token>`. The token must be a valid, non-revoked auth-service token (the caller's session token). inquiry-handler validates it by calling `auth-service GET /auth/verify` with the same bearer token; any non-200 response → `401`.

## `GET /admin/factor-settings`

Returns the effective weight of every **registered** factor and the raw stored map.

### Response `200`

```json
{
  "factors": [
    { "name": "scope_relevance", "weight": 0.5, "source": "stored" }
  ],
  "stored": { "scope_relevance": 0.5 }
}
```

- `stored` — the persisted weights map (factor-settings row). Empty object if none stored.
- `factors` — one entry per code-registered factor. `weight` is `stored[name] ?? config('scoring.default_factor_weight')`; `source` is `"stored"` or `"default"`.

### Errors

| Status | Body `detail` | Trigger |
|--------|---------------|---------|
| `401` | `Not authenticated.` | missing/invalid/revoked bearer token |

## `PUT /admin/factor-settings`

Replaces the stored weights for registered factors.

### Request

```json
{
  "weights": { "scope_relevance": 0.55, "urgency": 0.2 }
}
```

### Response `200`

```json
{
  "factors": [
    { "name": "scope_relevance", "weight": 0.55, "source": "stored" },
    { "name": "urgency", "weight": 0.2, "source": "stored" }
  ]
}
```

The updated `factors` array (same shape as GET) confirms the new effective weights.

### Validation / rules

- `weights` must be an object of factor-name → number.
- Each name **must exist in the code registry** — unknown names are rejected (`422`). Runtime cannot add/remove factors (FR-004).
- Each weight must be a **finite number ≥ 0** (`422` otherwise). Zero is allowed (disables a factor). No normalization is required on write; combination normalizes (R1).
- Only the provided names are written; weights of unnamed registered factors are left unset (they keep using their default).

### Errors

| Status | Body `detail` | Trigger |
|--------|---------------|---------|
| `401` | `Not authenticated.` | missing/invalid/revoked bearer token |
| `422` | e.g. `Unknown factor: budget_signal`, `Weights are required.`, `Weight for scope_relevance must be a number >= 0.` | shape/validation violation |
| `503` | `Settings store unavailable.` | weights store unreadable/unwritable when persisting |