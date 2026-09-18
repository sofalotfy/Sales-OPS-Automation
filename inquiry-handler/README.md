# Inquiry Handler (`inquiry-handler`)

API-first sales-inquiry classification service for the Sales Ops project. Its
public interface is a JSON API: a client submits a short message plus the seven
contact fields the inquiry form collects (`first_name`, `last_name`, `email`
required; `phone_number`, `company_name`, `country_region` optional); the
service classifies the inquiry against a **fixed system prompt** (the
deployment's company/scope statement) and computes a
**weighted multi-factor score**, maps it to one of
`high | medium | low | disqualify`, persists the run to its scoped store, and
replies with a visitor-facing placeholder. A minimal HTML test console is served
at `GET /` for manual/exercise testing only — it is not a product surface. See
`../../specs/008-inquiry-form-fields/` for the full spec, contracts, and plan.

## Flow

1. `POST /inquiry/triage` receives the seven inquiry-form fields:
   `{"message": "...", "first_name": "...", "last_name": "...", "email": "...",
   "phone_number": "?", "company_name": "?", "country_region": "?"}` — the three
   required contact fields plus `message` must be present; the rest are optional.
2. `MessageExtractor` normalizes and validates the payload; contact fields are
   carried along but **never sent to the AI**.
3. RAG context retrieval is no longer used: the only context each inquiry is
   judged against is the fixed classification system prompt
   (`App\Triage\SystemPrompt`, built from `COMPANY_SCOPE`). The same string is
   persisted verbatim on the record (`system_prompt`) so admins can audit it.
4. `ScoringEngine` iterates the code-registered `FactorRegistry`. Each factor
   service computes a 0–100 score (factors may use `AiCallingService::complete()`
   for AI), the received weights are read from the `factor_settings` row
   (default 1.0 when unset), and the engine combines them via the weighted mean
   `Σ(score·weight)/Σ(weight)`.
5. The final score maps through `config('scoring.thresholds')` to a
   `Classification` (`high|medium|low|disqualify`). With an empty factor catalog
   the result is score `0.00` + `low`. Failed factors are dropped and the
   remaining weights renormalize; a failed classification-log write never breaks
   the 200 response.
6. Every run is persisted to `classification_results` (append-only audit log).
   The reply is the placeholder copy for the resulting classification.

## Endpoints

| Method | Path                   | Purpose                                             |
| ------ | ---------------------- | --------------------------------------------------- |
| GET    | `/`                    | Test console (form; posts JSON to `/inquiry/triage`). |
| POST   | `/inquiry/triage`      | Run classification; weighted score + classification. |
| GET    | `/admin/factor-settings`  | List effective weights of every registered factor (bearer-verified). |
| PUT    | `/admin/factor-settings`  | Replace stored weights for registered factors (bearer-verified). |
| GET    | `/health`              | Liveness probe (used by the Compose healthcheck).   |

Admin endpoints require `Authorization: Bearer <auth-service token>` and are
validated against `auth-service GET /auth/verify`. See
`../../specs/006-weighted-factor-classification/contracts/factor-settings-admin.md`.

### `POST /inquiry/triage`

Success (`200`):

```json
{
  "classification": "low",
  "score": 0,
  "factor_scores": {},
  "dropped_factors": [],
  "reply": "Thank you for your inquiry. We will be in touch if there is a match.",
  "reasoning": "No factors are registered; catalog is empty.",
  "context": { "inquiry": { "message": "...", "name": null, "email": null } }
}
```

Errors:

- `400` — body is not a JSON object (e.g. `12345`).
- `422` — invalid payload (blank/oversized message, malformed email, …).
- `503` — unrecoverable upstream unavailability (defensive; normal failures
  degrade to the `low` fallback instead of ever erroring).

The endpoint is CSRF-exempt and bypasses `TrimStrings`/`ConvertEmptyStringsToNull`
so payloads arrive unmutated for precise validation. The test console page is
served normally (full CSRF applies to browser routes); the admin routes are also
CSRF-exempt because they authenticate via bearer token, not the session.

## Adding a factor (`ScoreFactor`)

The factor SET is code-registered only (FR-004) — the dashboard can tune weights
for existing factors but can never add one at runtime. Adding a factor:

1. **Write a service** implementing `App\Scoring\ScoreFactor`:

   ```php
   namespace App\Scoring; // or your own namespace

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

3. **Tune its weight** (optional) from the dashboard "Factor weights" tab, or via
   `PUT /admin/factor-settings` with `{"weights": {"scope_relevance": 0.5}}`.
   Unknown names are rejected with `422`.

That's it — the engine, triage flow, admin API, and dashboard pick the factor up
automatically (SC-003).

## Configuration

All knobs are env-driven (see `.env.example`):

| Env var                        | Meaning                                              |
| ------------------------------ | ---------------------------------------------------- |
| `AUTH_API_URL`                 | `auth-service` base URL (e.g. `http://auth-service:8001`). |
| `RAG_API_URL`                  | `work-scope-rag` base URL (`http://work-scope-rag:8000`). Retained (unused) for future re-enabling of context retrieval. |
| `DB_*`                         | Scoped store (`inquiry_handler`) for weights + classification log. |
| `SERVICE_USERNAME`             | Service account for the RAG bearer token.            |
| `SERVICE_PASSWORD`             | Service account password. Never commit a real value. |
| `ZAI_API_KEY`                  | Z.AI API key. Never commit a real value.             |
| `ZAI_MODEL`                    | Default (scope/general) Z.AI model id (default `glm-4.5-flash`). |
| `ZAI_RESEARCH_MODEL`           | Model id for the AI research agent's filter + summarize steps (default `glm-4.7-flash`). |
| `ZAI_TIMEOUT`                  | Per-request AI HTTP timeout in seconds (default 90; free-tier flash models are slow). |
| `WEB_RESEARCH_FILTER_ATTEMPTS` | AI filter retries on unparseable output (default 2). |
| `WEB_RESEARCH_RESCUE_ON_NAME_MATCH` | Best-effort rescue for scarce data: fetch + summarize name-matching candidates and mark the result `uncertain` (default `true`). |
| `COMPANY_SCOPE`                | Company/scope statement injected into the fixed system prompt (persisted per inquiry as `system_prompt`). |
| `BOOKING_URL`                  | Booking link substituted into the `high` reply only when it is a valid URL. |
| `MESSAGE_MAX_LENGTH`           | Max message length (default 4000).                   |
| `RAG_TOP_K`                    | Unused since context retrieval is disabled (kept for future use; default 5). |
| `SCORING_THRESHOLD_HIGH/MEDIUM/LOW`, `SCORING_DEFAULT_FACTOR_WEIGHT`, `SCORING_SCORE_MIN/MAX` | Scoring tunables (defaults in `config/scoring.php`). |

## Run & test

The Compose stack runs the handler at `http://localhost:8003` (healthcheck hits
`GET /health`; depends on `db`, `auth-service`, and `work-scope-rag` being
healthy). Migrations run automatically from the entrypoint.

```sh
docker compose up --build -d
composer install        # local dev
php artisan test        # full suite must stay green (uses SQLite :memory:)
```

## Design notes

- **Scoped store**: only this service holds `DB_*`; factor weights and the
  classification log live in the `inquiry_handler` database. Tests run on SQLite
  `:memory:` and never touch it (research R9).
- **Classification completes even on failures** (FR-007/FR-008): failed factors
  drop with renormalization, an unreadable weights store falls back to defaults,
  and a failed log write is logged but never blocks the response.
- **Advisory-only classification** (constitution principle III): every inquiry
  remains visible to a human reviewer; `high/medium/low/disqualify` is guidance,
  not an action.
- **Superseded triage classes are kept**: `App\Triage\*` and
  `AiCallingService::triage()` are marked `@deprecated`, retained for reference,
  and never invoked by the new flow (FR-012).
- **No frontend build step**: the test console page is a single Blade view with
  inline CSS; no Node/Vite toolchain.