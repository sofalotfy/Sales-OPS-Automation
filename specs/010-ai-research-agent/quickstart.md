# Quickstart: AI Research Agent for Company & Person Enrichment

**Feature**: 010-ai-research-agent · **Date**: 2026-09-18

Runnable validation scenarios that prove the feature end-to-end. Contract and
entity details live in [contracts/](contracts/) and [data-model.md](data-model.md);
implementation belongs to `tasks.md` and the implement phase.

## Prerequisites

- Docker Compose stack from the repo root.
- `.env` (inquiry-handler) with valid `TAVILY_API_KEY` and `ZAI_API_KEY`.
- New budget knobs are optional; defaults apply (see `config/web_research.php`):

  ```env
  WEB_RESEARCH_ENABLED=true
  WEB_RESEARCH_MAX_CANDIDATES=12
  WEB_RESEARCH_MAX_SOURCES=5
  WEB_RESEARCH_FETCH_TIMEOUT=8
  WEB_RESEARCH_STEP_TIMEOUT=25
  ```

## Setup / run

```sh
docker compose up --build -d
docker compose ps          # inquiry-handler healthy at http://localhost:8003
```

Health check:

```sh
curl -s http://localhost:8003/health
```

## Scenario 1 — Findable company + person → grounded summary (SC-001/SC-002)

```sh
curl -s -X POST http://localhost:8003/inquiry/triage \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane@example.com",
    "phone_number": "+1 555 0132",
    "company_name": "Example Corp",
    "country_region": "United Kingdom",
    "message": "We need a CRM built."
  }' | jq '.context.web_research'
```

**Expected**: `outcome = "accept"` and `findings.outcome = "completed"` with a
non-empty `findings.summary` and a `findings.sources` list. Every source URL was
actually fetched (no raw `company`/`person` result lists anywhere). The inquiry
still returns a normal classification response (`200`).

## Scenario 2 — Unfindable company → honest `not_found` (SC-003)

```sh
curl -s -X POST http://localhost:8003/inquiry/triage \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane@example.com",
    "company_name": "Acme Nowhere LLC 98765",
    "message": "We need a CRM built."
  }' | jq '.context.web_research.findings'
```

**Expected**: `outcome = "not_found"`, an explicit statement in `summary`, no
invented facts, and the HTTP status is still `200`.

## Scenario 3 — Ambiguous / shared name → `ambiguous` (Story 3)

Submit a company name shared by multiple distinct entities without a
`country_region`; expect `findings.outcome = "ambiguous"` and a statement asking
for disambiguation — never a single guessed entity. Repeat with a
`country_region` that uniquely resolves it and expect `completed`.

## Scenario 4 — Failure is fail-open, never fabricated (SC-004)

Temporarily unset `TAVILY_API_KEY` (or `ZAI_API_KEY`) and restart the handler,
then repeat Scenario 1.

**Expected**: `context.web_research.outcome = "indeterminate"`, empty findings,
HTTP `200`, and the full classification response still returned. No partial or
fabricated summary is presented as complete.

## Scenario 5 — Kill switch (unchanged behavior)

```sh
WEB_RESEARCH_ENABLED=false
```

Restart and repeat Scenario 1: the response must have **no**
`context.web_research` key, and the persisted row has
`web_research_outcome = null` / `web_research = null`.

## Scenario 6 — Recorded and reviewable (SC-006)

The enrichment is persisted on the classification row and readable through the
admin API (bearer token required):

```sh
curl -s http://localhost:8003/admin/classification-results/1 \
  -H "Authorization: Bearer <auth-service-token>" | jq '.web_research'
```

**Expected**: the new `criteria` + `findings` (`outcome`, `summary`, `sources`,
`limitations`) shape — see
[contracts/classification-reporting.md](contracts/classification-reporting.md).

## Automated validation

```sh
cd inquiry-handler
php artisan test          # full suite must stay green (SQLite :memory:)
```

Relevant suites: `tests/Unit/ResearchAgentTest`, `tests/Unit/PageFetcherTest`,
`tests/Unit/WebResearchServiceTest`, `tests/Feature/WebResearchMiddlewareTest`,
`tests/Feature/ClassificationResultsAdminTest`.

Privacy assertion (SC-007): the unit tests assert that no outbound provider, AI,
or fetch request ever contains email/phone; a live check is to inspect
`docker compose logs inquiry-handler` while running Scenario 1 and confirm no
contact fields appear in outbound requests.

## Pass/fail summary

| Scenario | Pass condition |
|---|---|
| 1 | `completed` summary + fetched `sources`, `200` |
| 2 | `not_found`, no invented facts, `200` |
| 3 | `ambiguous` without disambiguating country; `completed` with it |
| 4 | `indeterminate` fail-open, `200`, no fabricated summary |
| 5 | no `web_research` marker / null columns |
| 6 | persisted new shape visible via admin detail |
| Tests | `php artisan test` green; SC-007 privacy assertions pass |
