# Quickstart: Weighted Multi-Factor Inquiry Classification

**Feature**: [006-weighted-factor-classification](spec.md) · Validation guide (Phase 1 of `/speckit.plan`).

These scenarios prove the feature works end-to-end. Implementation details live in [tasks.md](tasks.md) (created by `/speckit.tasks`).

## Prerequisites

- Docker Compose stack running: `docker compose up --build -d` (db, auth-service, work-scope-rag, inquiry-handler, dashboard).
- Migration applied automatically by the inquiry-handler entrypoint (`php artisan migrate --force`). Seeded single factor-settings row has **no factors** (`weights: {}`) — the catalog is empty until factors are added in development.
- Env: inquiry-handler has `DB_*` pointing at `inquiry_handler`; dashboard has `INQUIRY_HANDLER_URL=http://inquiry-handler:8003`.

## Automated validation

Run the inquiry-handler suite (SQLite in-memory; no Postgres needed):

```bash
cd inquiry-handler && composer test
```

Run the dashboard suite:

```bash
cd dashboard && composer test
```

Expected: all feature/unit tests pass, including the new scoring + admin-tab tests and the untouched legacy-class unit tests.

## Manual validation

### 1. Empty-catalog fallback classification

With the catalog empty, every triage returns the `low` fallback:

```bash
curl -s -X POST http://localhost:8003/inquiry/triage \
  -H 'Content-Type: application/json' \
  -d '{"message": "Do you offer annual maintenance contracts?"}'
```

Expect: `200`, `"classification": "low"`, `"score": 0.0`, `"factor_scores": {}`. Contract: [inquiry-web.md](contracts/inquiry-web.md).

### 2. Admin reads weights (empty catalog)

```bash
curl -s http://localhost:8003/admin/factor-settings \
  -H "Authorization: Bearer $DASHBOARD_TOKEN"
```

Expect `200` with `"factors": []`. No token / bad token → `401`.

### 3. Weights API rejects an unknown factor (FR-004)

```bash
curl -s -X PUT http://localhost:8003/admin/factor-settings \
  -H "Authorization: Bearer $DASHBOARD_TOKEN" -H 'Content-Type: application/json' \
  -d '{"weights": {"made_up_factor": 0.5}}'
```

Expect `422` `detail` mentioning the unknown factor.

### 4. Adding a factor changes behavior (SC-003)

When a developer adds the first factor service and registers it (catalog no longer empty):

- `GET /admin/factor-settings` lists it with `source: "default"`.
- Triage of a relevant inquiry now shows a non-empty non-zero `factor_scores` and a real weighted `score`/`classification` instead of the empty fallback.
- A factor whose value cannot be computed (e.g., its model source is down) is listed under `dropped_factors` and classification still returns — instead of an error.

### 5. Admin edits weights from the dashboard (SC-004)

1. Log into the dashboard (`http://localhost:8002` → login, e.g. `admin`/`dev-admin-password-123`).
2. Open the **Factor weights** tab (nav link).
3. Change a factor's weight, save.
4. Submit a matching inquiry to `/inquiry/triage` and confirm the new weight is reflected in `factor_scores` — no redeploy.

### 6. Scoped store (SC-006)

- The dashboard never connects to `inquiry_handler` DB; only inquiry-handler holds the `DB_*` credentials.
- Verify the dashboard's only talk to inquiry-handler goes to `/admin/factor-settings` over HTTP.
- Optional: in the Postgres container (`docker compose exec db psql -U rag -d rag`), confirm `inquiry_handler` has only its own tables.

## Contract references

- [contracts/inquiry-web.md](contracts/inquiry-web.md) — triage request/response/errors.
- [contracts/factor-settings-admin.md](contracts/factor-settings-admin.md) — admin weights API + auth.
- [data-model.md](data-model.md) — factor-settings and classification-result shapes.