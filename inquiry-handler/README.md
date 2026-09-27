# Inquiry Handler (`inquiry-handler`)

The triage service in the Sales Ops project. A client submits a short inquiry —
their question plus the contact fields the form collects — and this service
decides how well that request matches the served scope, then answers with a
reply a visitor can be shown.

The judgement is a **weighted multi-factor score**. Each registered factor
scores the inquiry 0–100, the scores are combined as a weighted mean, and the
result maps to one of `high | medium | low | disqualify`. Two gates sit in front
of the scoring engine — web research and a scope check — and either can decline
an inquiry outright. Every run is written to an append-only log, and every
inquiry stays visible to a human reviewer: the classification is **guidance for
a human, never an automated action**.

A test console is served at `GET /` for manual verification. It is a testing
aid, not a product surface — see [Test console](#test-console).

## API

The JSON API is the primary interface. All three routes below authenticate with
the shared `X-CRM-Key` credential (the `crm.key` middleware), except `/health`.

| Method | Path              | Purpose                                                     |
| ------ | ----------------- | ----------------------------------------------------------- |
| POST   | `/inquiry/triage` | Accept an inquiry, open a run row, hand it to the queue.     |
| GET    | `/inquiry/{id}`   | Poll one run until it reaches a verdict.                      |
| GET    | `/`               | Test console (posts to `/inquiry/triage`, then polls).        |
| GET    | `/health`         | Liveness probe (Compose healthcheck).                         |

Admin routes — `/admin/factor-settings`, `/admin/classification-results`,
`/admin/sectors` — are bearer-token routes verified against
`auth-service GET /auth/verify`, and are consumed by the dashboard rather than
by clients.

### `POST /inquiry/triage`

Body: the seven form fields.

```json
{
  "message": "We need a B2B ordering portal for our Shopify Plus store.",
  "first_name": "Jane",
  "last_name": "Doe",
  "company_name": "Northwind Distribution",
  "email": "jane@example.com",
  "phone_number": null,
  "country_region": "United Kingdom"
}
```

`first_name`, `last_name`, `email`, and `message` are required; the other three
are optional. `campaign_id` and `lead_id` may also be supplied for CRM callers
— the run row is idempotent per that pair.

Responses:

- `202 {"inquiry_id": 7, "status": "queued", ...}` — accepted and queued.
- `409 {...,"status":"existing"}` — this `(campaign_id, lead_id)` was already
  accepted. Not re-run, no double spend; poll the same `inquiry_id`.
- `400` — body is not a JSON object (e.g. `12345`).
- `422` — invalid payload (blank/oversized message, malformed email, …).
- `503` — the queue is unavailable. The service never fabricates a result.

The endpoint is CSRF-exempt and bypasses `TrimStrings`/`ConvertEmptyStringsToNull`
so payloads arrive unmutated for precise validation.

### `GET /inquiry/{id}`

The CRM poll payload. `status` moves through the pipeline and `result` stays
`null` until the run succeeds:

```
queued → processing → researching → scope_check → scoring → succeeded
                                                           ↘ failed
```

```json
{
  "inquiry_id": 7,
  "campaign_id": "test-console",
  "lead_id": "console-…",
  "status": "succeeded",
  "result": {
    "classification": "high",
    "score": 82.4,
    "factor_scores": {
      "company_size": { "score": 90, "weight": 1.0, "reasoning": "…" },
      "industry_sector": { "score": 78, "weight": 1.5, "reasoning": "…" }
    },
    "dropped_factors": [],
    "reply": "You are in the right place. Pick a time here: https://…",
    "reasoning": "…",
    "context": { "inquiry": {}, "system_prompt": "", "web_research": {} }
  },
  "error": null
}
```

Unknown id → `404`.

## Pipeline

1. **Accept.** `MessageExtractor` normalises and validates the payload. The
   contact fields are carried along but **never sent to the AI**.
2. **Queue.** A run row is created `queued` with the payload fields only, then
   `ProcessTriageJob` is dispatched to Redis. If the dispatch fails the row is
   deleted rather than left promising work that will never happen.
3. **Web research** (optional, `WEB_RESEARCH_ENABLED`). Looks the company and
   contact up, and records what it found. A missing provider key fails this
   stage open as `indeterminate` rather than failing the run.
4. **Scope gate** (optional, `SCOPE_GATE_ENABLED`). Asks whether the request is
   something the company actually does. A decline short-circuits to `succeeded`
   with a `disqualify` envelope and a refusal — it never reaches scoring.
5. **Scoring.** `ScoringEngine` walks the code-registered `FactorRegistry`. Each
   factor returns a 0–100 `FactorVerdict`; weights come from the
   `factor_settings` row (default `1.0`), and the engine combines them as
   `Σ(score·weight) / Σ(weight)`. The result maps through
   `config('scoring.thresholds')` to a `Classification`, and lands in the log
   exactly once.
6. **Reply.** A per-classification placeholder from `config('scoring.replies')`.
   The `high` reply has `{booking_url}` substituted only when
   `BOOKING_URL` is a valid URL.

### Failure handling

Classification completes even when things go wrong:

- A factor that throws is **dropped**; the remaining weights renormalize and the
  drop is recorded in `dropped_factors`.
- An unreadable weights store falls back to the configured defaults.
- A failed log write is logged but never blocks the response.
- With an empty factor catalog the result is score `0.00` and `low` — never
  `disqualify`.
- An unrecoverable run lands `failed` with `error` set, and stops spending.

## Factors

The factor **set** is code-registered only. The dashboard can tune weights for
existing factors but can never add one at runtime.

Registered today:

| Factor             | What it scores                                     |
| ------------------ | -------------------------------------------------- |
| `company_size`     | Whether the prospect's headcount suits the engagement. |
| `industry_sector`  | Whether the inquiry's sector is one the company serves. |

To add one:

1. **Write a service** implementing `App\Scoring\ScoreFactor`:

   ```php
   namespace App\Scoring;

   final class ScopeRelevanceFactor implements ScoreFactor
   {
       public function name(): string
       {
           return 'scope_relevance';
       }

       public function score(array $inquiry, array $context): FactorVerdict
       {
           // Optionally call the AI (JSON in -> array or null out):
           // $ai = app(AiCallingService::class)->complete($system, $user);
           return new FactorVerdict(90, 'message matches served scope', ['ok' => true]);
       }
   }
   ```

2. **Register it** in `App\Providers\AppServiceProvider::register()`:

   ```php
   $this->app->singleton(FactorRegistry::class, fn ($app) => (new FactorRegistry)->add(new ScopeRelevanceFactor));
   ```

3. **Tune its weight** from the dashboard, or via `PUT /admin/factor-settings`
   with `{"weights": {"scope_relevance": 0.5}}`. Unknown names are rejected
   with `422`.

The engine, the log, and the dashboard pick it up from there.

## Test console

`GET /` is a manual-testing aid served by this app. It presents the same
inquiry form a client would fill in, submits it to `POST /inquiry/triage`, then
polls `GET /inquiry/{id}` until the run resolves and renders:

- the pipeline as a plain-language checklist, so a slow run reads as progress
  rather than a spinner;
- the **visitor-facing reply** — the same text a client would be shown, with the
  booking link promoted to a real button on a `high` verdict;
- a collapsed **operator panel** with the run id, elapsed time, classification,
  score, per-factor scores and weights, dropped factors, reasoning, and the raw
  poll JSON.

Three sample requests are pre-filled as chips (in scope, borderline, out of
scope) to make exercising the pipeline quick.

The page is labelled a test console at the top so its role is never ambiguous.
It reads the `X-CRM-Key` from server-side config instead of asking the tester to
paste it, and is never rendered for an unauthenticated caller of the API.

## Configuration

All knobs are env-driven (see `.env.example`).

| Env var | Meaning |
| ------- | ------- |
| `AUTH_API_URL` | `auth-service` base URL. |
| `RAG_API_URL` | `work-scope-rag` base URL. Retained (currently unused) for re-enabling context retrieval. |
| `DB_*` | Scoped store (`inquiry_handler`) for weights, sector settings, and the classification log. |
| `CRM_API_KEY` | Shared credential the CRM presents as `X-CRM-Key`. Empty tight-shuts the inquiry surface. |
| `SERVICE_USERNAME` / `SERVICE_PASSWORD` | Service account for the bearer token. |
| `AI_API_KEY` / `AI_API_URL` | AI chat-completions provider (OpenAI-compatible; default Groq). |
| `AI_MODEL` | Model for scope checks and non-research calls. |
| `AI_RESEARCH_MODEL` | Model for the research agent's notes and final extraction. |
| `AI_FILTER_MODEL` | Model for the research candidate filter (cheap and fast by design). |
| `AI_CONCURRENCY` | Max in-flight AI requests when a step fans out (default 2). |
| `AI_MAX_OUTPUT_TOKENS` | Cap on generated tokens per call (default 2048). |
| `AI_TIMEOUT` | Per-request AI HTTP timeout in seconds (default 90). |
| `AI_GUARD_*` | Shared RPM, in-flight, and token-per-minute budget across all workers. Keep the token budget just under the provider's cap. |
| `AI_GUARD_WAIT_SECONDS` | How long a deferred job waits for a guard slot before failing open. |
| `TAVILY_API_KEY` | Web-research search provider. Missing key fails the step open as `indeterminate`. |
| `WEB_RESEARCH_ENABLED` | Whether the research stage runs. |
| `WEB_RESEARCH_MAX_CANDIDATES` / `_MAX_SOURCES` | Candidates considered before the AI filter, and sources kept and cited. |
| `WEB_RESEARCH_SUMMARY_MAX_INPUT_CHARS` | Below this, documents go through one final extraction; above it they go through layer-1 per-page notes, whose parts are merged so nothing is dropped. |
| `WEB_RESEARCH_FETCH_TIMEOUT` / `_FETCH_CONCURRENCY` | Per-page fetch timeout and simultaneous downloads per batch. |
| `WEB_RESEARCH_STEP_TIMEOUT` | Wall-clock budget for one research run. |
| `WEB_RESEARCH_FILTER_ATTEMPTS` / `_NOTE_ATTEMPTS` | Retries on unparseable AI output. |
| `WEB_RESEARCH_RESCUE_ON_NAME_MATCH` | Best-effort rescue for scarce data; marks the result `uncertain`. |
| `SCOPE_GATE_ENABLED` | Whether the scope gate runs. |
| `BOOKING_URL` | Booking link substituted into the `high` reply only when it is a valid URL. |
| `MESSAGE_MAX_LENGTH` | Max message length (default 4000). |
| `SCORING_THRESHOLD_HIGH/MEDIUM/LOW`, `SCORING_DEFAULT_FACTOR_WEIGHT`, `SCORING_SCORE_MIN/MAX` | Scoring tunables (defaults in `config/scoring.php`). |
| `QUEUE_CONNECTION`, `REDIS_*` | Queue transport for `ProcessTriageJob`. |

Scope itself is not env-driven: it is a fixed statement hardcoded in
`config/services.php` (`company_scope`) so the triage persona cannot be
influenced by a request.

## Run & test

The handler is served at `http://localhost:8003` and depends on `db`,
`auth-service`, and `work-scope-rag` being healthy. Migrations run from the
entrypoint. The pipeline itself runs on the `inquiry-worker` container, not in
the web process.

```sh
docker compose up --build -d
composer install        # local dev
php artisan test        # full suite must stay green (uses SQLite :memory:)
```

Scale the workers with:

```sh
docker compose up -d --scale inquiry-worker=3
```

## Design notes

- **Scoped store.** Only this service holds `DB_*`; factor weights, sector
  settings, and the classification log live in the `inquiry_handler` database.
  Tests run on SQLite `:memory:` and never touch it.
- **The log is append-only.** Result fields are written exactly once, at
  completion; stages write only their own columns before that. Every inquiry
  therefore remains auditable after the fact.
- **Advisory only.** Every inquiry stays visible to a human reviewer.
  `high/medium/low/disqualify` is guidance, not an action — nothing is
  auto-answered to a customer and no lead is dropped on a score.
- **Idempotent intake.** The unique `(campaign_id, lead_id)` index means a
  retried submission re-acknowledges the existing run instead of re-running it
  and double-spending the AI budget.
- **Crash-recovery ladder.** redis `retry_after` (900) > worker `--timeout`
  (600) > job `$timeout` (590). Raising any research or AI budget means raising
  all three in concert — never only one.
- **Superseded triage classes are kept.** `App\Triage\*` and
  `AiCallingService::triage()` are marked `@deprecated`, retained for reference,
  and never invoked by the current flow.
- **No frontend build step.** The console is a single Blade view with inline
  CSS; no Node/Vite toolchain.
