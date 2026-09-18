# Research: Weighted Multi-Factor Inquiry Classification

**Feature**: [spec.md](spec.md) — Phase 0 output of `/speckit.plan`.

This document resolves the design decisions needed before Phase 1. Format per artifact: Decision, Rationale, Alternatives considered.

## R1. Score and weight scales

- **Decision**: Factor scores are integers/decimals in **[0–100]**; weights are **non-negative decimals** (no upper bound and no normalization requirement on write). Combination = `Σ(scoreᵢ × weightᵢ) / Σ(weightᵢ)` over contributing factors → final score in [0–100]. Weights are normalized once at combination time.
- **Rationale**: A 0–100 scale is human-readable for the dashboard tab (which shows each factor's score) and maps directly to percentage-style thresholds. Normalizing at combination time satisfies FR-003 and the spec assumption that stored weights "may be non-normalized". Dropping a failed factor and renormalizing (clarification Q3) falls out naturally from the same formula — a factor with no score is simply excluded from both numerator and denominator.
- **Alternatives considered**: 0–1 decimal scale (less readable in the UI); weights required to sum to 1.0 (fragile, forced every consumer to normalize; rejected because the engine normalizes anyway).

## R2. Classification thresholds and boundary rule

- **Decision**: Config-driven thresholds `config/scoring.php`: `high ≥ 75`, `medium ≥ 55`, `low ≥ 30`, else `disqualify`. Boundary rule: the lower bound is **inclusive** (a final score of exactly 75 is `high`, exactly 55 is `medium`, exactly 30 is `low`).
- **Rationale**: Inclusive lower bounds give an unambiguous, stable rule for the "lands on a threshold" edge case (spec Edge Cases). Thresholds are data (config), not code, so reviewing/adjusting them is a config change, consistent with weights being runtime-adjustable.
- **Alternatives considered**: Exclusive lower bounds (bias toward the lower bucket); a single tunable per region. Inclusive-lower is simplest and reduces off-by-one acceptance questions.

## R3. Missing-data and failure semantics

- **Decision**: (a) **Empty catalog** → `final_score = 0.0`, classification **`low`** (clarification Q2; relaxing the otherwise disqualified bucket). (b) **Failed factor** → dropped; remaining weights renormalize (clarification Q3). (c) **Stored weights unreadable (DB unavailable)** → fall back to `config('scoring.default_factor_weight')` per registered factor and proceed. (d) **Classification-result write fails** → log via `Log::error`, still return the 200 classification response. (e) **Factor score out of range** → clamp into [0,100].
- **Rationale**: (a) and (b) are pinned by the clarification session. (c) keeps classification working when only the weights store is down (SC-005: "log store unavailable"; extended to weights store). (d) matches "the inbound response is still delivered" from User Story 4. (e) guards corrupt/API factor implementations.
- **Alternatives considered**: Neutral mid-score for failed factors (rejected in clarification, chose drop+renormalize); hard-fail on DB error (rejected — violates "classification completes even when a helper fails").

## R4. Factor service interface and registry

- **Decision**: One interface, one registration point.
  - `App\Scoring\ScoreFactor`: `name(): string`; `score(array $inquiry, array $context): FactorVerdict`.
  - `FactorVerdict` value object: `{int score /*0-100*/, string reasoning, array meta}`.
  - `App\Scoring\FactorRegistry`: an injectable map of `name => ScoreFactor` instances, **registered in code** in `AppServiceProvider` (starts empty). The engine iterates the registry; the dashboard/PUT endpoint only ever adjusts stored weights for names that already exist in the registry — enforcing FR-004 (factor set dev-only).
- **Rationale**: "Add a factor = write one service + register it" is the exact acceptance in SC-003. The registry being code-backed (not DB-backed) makes runtime add/remove impossible by construction.
- **Alternatives considered**: Store the factor set in the DB alongside weights (rejected — would allow runtime factor changes, violating FR-004); a strategy/dispatcher pattern (overkill for v1).

## R5. Per-factor AI access

- **Decision**: Repurpose the existing AI caller. `AiCallingService` keeps its current Z.AI HTTP mechanics and gains a generic method `complete(string $system, string $user): ?array` that returns decoded JSON or `null` on any failure/parse error; the triage-specific `triage()` path and `PromptBuilder` are retained but marked `@deprecated` (FR-012 — never deleted). Future factor services build their own system/user prompts and call `complete()`.
- **Rationale**: "AI is per factor" (spec Assumptions) and "add a factor as a service" (SC-003) mean each factor owns its prompt. Keeping the existing client, not forking it, honors reuse-with-tagging. `null` on failure feeds directly into the drop-and-renormalize path (R3b).
- **Alternatives considered**: New dedicated `AiClient` class (rejected — duplicates HTTP/JSON-mode/422-fallback logic already in `AiCallingService`); a shared "classify the whole inquiry" call (rejected — that is the old single-shot model being replaced).

## R6. Admin weights API on inquiry-handler + auth

- **Decision**: New endpoints on inquiry-handler:
  - `GET  /admin/factor-settings` → the effective weight of every **registered** factor (`{factors: [{name, weight, source: "stored"|"default"}]}`), plus the raw stored map for transparency.
  - `PUT  /admin/factor-settings` → body `{weights: {name: number, ...}}`; the service writes only the **stored** map for known registered factor names (unknown names → 422; non-positive/non-numeric weights → 422). Enforces FR-004.
  - Both behind `App\Http\Middleware\VerifyUpstreamToken`: validates the `Authorization: Bearer` token by calling **auth-service `GET /auth/verify`** with that token; non-200 → `401`.
- **Rationale**: Mirrors the stack's established pattern (RAG `require_valid_token` verifies caller tokens via auth-service `/auth/verify`). The dashboard already holds a valid auth-service session token (`UpstreamSession::token()`), so no new credential type is needed. Requiring a *stored* weight only for *registered* names guarantees runtime cannot add factors (FR-004). Update is a bare-bones, documented contract (FR-005).
- **Alternatives considered**: Shared static secret env `ADMIN_API_TOKEN` on both services (rejected — introduces a new secret class and drift risk; the repo already standardizes on auth-service bearer verification); direct dashboard→DB (rejected — constitution I/FR-010); no role check at all (accepted — dashboard gates by `auth.upstream`; role enforcement is deferred, see R7).

## R7. Dashboard admin tab

- **Decision**: Gated by the existing `auth.upstream` middleware (any signed-in user, matching every other page — the dashboard has no role model today). Implementation mirrors the Documents resource: `routes/web.php` `GET|PUT /factor-weights` inside `auth.upstream`; new `FactorWeightsController` (index loads via `InquiryHandlerApiClient::getFactorSettings(UpstreamSession::token())`, redirects to `login.show` on 401, surfaces `detail` errors via `x-upstream-error`; update validates weights server-side then PUTs); new `resources/views/factor-weights/index.blade.php` (card form, numeric input per factor); nav link in `layouts/app.blade.php`.
- **Rationale**: Smallest version (constitution V) — no Livewire, no new role plumbing. Reuses the client/controller/stub/session conventions the dashboard already exercises.
- **Alternatives considered**: Livewire component (the documents editor uses it; viable, but a plain controller+Blade form is fewer moving parts for a single-field-per-factor form); role-based gating via `/auth/verify`'s `role` (deferred — the dashboard would need to fetch+store the role; not a stated requirement; revisit when roles matter).

## R8. Storage provisioning (scoped DB)

- **Decision**: Add to `db/init.sql` (executed as the `rag` superuser at first boot): `CREATE DATABASE inquiry_handler OWNER rag;` and `CREATE DATABASE inquiry_handler_test OWNER rag;`, matching the existing `rag`/`auth` (+`_test`) pattern. Only the inquiry-handler service receives `DB_*` env (host `db`, db `inquiry_handler`, user `rag`, password `rag`) in `docker-compose.yml`; no other service references that database name. `docker-compose.yml`: inquiry-handler gains `depends_on: db` (condition healthy) and the `DB_*` env; `Dockerfile` adds `libpq-dev` + `pdo_pgsql`; `docker-entrypoint.sh` runs `php artisan migrate --force` before serving.
- **Rationale**: Consistent with how `auth-service` gets `DATABASE_URL=.../auth`. The "not exposed to any other service" requirement is met because the database name/credentials exist only in inquiry-handler's environment; the shared Postgres instance is the project's existing single DB host.
- **Alternatives considered**: Dedicated role `inquiry_handler` with its own password (stronger isolation but hard-coding a password into `init.sql` is worse than the gated-env approach; revisit if cross-service DB isolation becomes a hard requirement); per-service Postgres container (rejected — new infra, contradicts the shared-`db` design).

## R9. Testing strategy

- **Decision**: Inquiry-handler tests use **SQLite `:memory:` + `RefreshDatabase`** (set in `phpunit.xml`/`TestCase`) so migrations verify the schema without touching the scoped store. Feature tests keep `Http::fake()` via `UpstreamStubs` (extended with admin-token/verify fakes + new triage response stubs). Scoring is covered at unit level (registry, scorer, engine, thresholds, drop/renormalize, empty-catalog → low, clamp). Dashboard tests follow the existing pattern (`signIn()` + `UpstreamStubs::inquiryUrl()` + `Http::fake`).
- **Rationale**: Keeps migrations portable (Postgres + SQLite), avoids the shared DB in CI/local tests, and matches the dashboard's established test style (spec Assumptions: "tests use a throwaway store").
- **Alternatives considered**: Test against `inquiry_handler_test` Postgres (works but requires a running db in test runs; SQLite is lighter and satisfies the assumption).

## R10. Score/weights JSON shape stored per classification

- **Decision**: `factor_scores` column stores `{ "<factor name>": { "score": int, "weight": float, "reasoning": string }, ... }` — one entry per factor that contributed (failed/dropped factors are recorded separately in `context` as a `dropped_factors` list so the audit trail can reconstruct the run). Top-level `final_score`, `classification`, `reasoning`, and `context` are separate columns (see data-model.md).
- **Rationale**: FR-009 requires the log to reconstruct how a decision was reached; recording *why* a factor was dropped is part of reconstructability (User Story 4).
- **Alternatives considered**: Drop dropped-factors entirely (rejected — audit loses the reason); flat columns per factor (rejected — schema is open-ended by design since factors are dev-added).

## R11. Config layout

- **Decision**: New `config/scoring.php` with: `score_min`/`score_max` (0/100), `default_factor_weight` (1.0), `thresholds` (`high` 75, `medium` 55, `low` 30), `replies` (per-classification placeholder strings; high uses the configured `services.booking_url` with the existing URL guard), and `empty_catalog_classification` (low). All tunables env-overridable where sensible.
- **Rationale**: Keeps thresholds/weights defaults/replies as data, not code (mirrors how `company_scope` is env-driven today), and keeps the reply copy out of PHP logic (placeholder per the spec assumption, to be confirmed with sales).
- **Alternatives considered**: Reply strings in a `ReplyBuilder` service (unnecessary indirection for placeholders); thresholds hard-coded in the engine (rejected — FR-003 wants tunable mapping).