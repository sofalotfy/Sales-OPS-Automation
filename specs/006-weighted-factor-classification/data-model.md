# Data Model: Weighted Multi-Factor Inquiry Classification

**Feature**: [spec.md](spec.md) — Phase 1 output of `/speckit.plan`.
See [research.md](research.md) for the decisions these shapes implement (R1–R11).

## Entity: Factor Settings

Runtime-editable weights for the code-registered factor set. A **single row** (singleton), keyed implicitly (id = 1). Factor names never here — they live in code (`FactorRegistry`); this row only stores their weights (FR-004).

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint pk | no | always one row |
| `weights` | json | no | `{"<factor name>": <float weight>, ...}` — empty `{}` at seed |
| `created_at` / `updated_at` | timestamps | — | |

Validation / rules:
- Weight values are non-negative decimals (no normalization required on write — R1).
- Keys in `weights` are matched against the code registry at usage and on admin PUT; unknown keys are ignored on read and rejected (422) on PUT (R6).
- Single-row invariant: reads use the first row (`Factor::instance()` fetches/creates row id=1). No other table writes weights.

Model: `App\Models\Factor`
- Casts `weights` → array. Helpers: `weights(): array`, `weightFor(string $name): ?float`, `instance(): self` (fetch-or-create the singleton row).
- Read path failure (DB unavailable) → engine falls back to `config('scoring.default_factor_weight')` (R3c) and logs.

## Entity: Classification Result

One row per classified inquiry (FR-009). Pure append log; never updated after insert.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint pk | no | |
| `inquiry_message` | text | no | normalized message (max 4000) |
| `name` | string(255) | yes | contact optional |
| `email` | string(255) | yes | contact optional |
| `retrieved_context` | json | no | `{result_count: int, results: [...]}` (empty results default) |
| `system_prompt` | text | yes | fixed classification system prompt (company/scope context) each inquiry was judged against; `null` for rows written before this field existed |
| `factor_scores` | json | no | per-contributing-factor detail (shape below) |
| `dropped_factors` | json | no | `[{name, reason}]` for factors that failed to compute (R10) |
| `final_score` | numeric(5,2) | no | 0.00–100.00 |
| `classification` | string(20) | no | one of `high|medium|low|disqualify` |
| `reasoning` | text | yes | overall justification for the run |
| `created_at` | timestamp | no | |
| `updated_at` | timestamp | no | |

`factor_scores` JSON shape (R10):

```json
{
  "scope_relevance": { "score": 88, "weight": 0.5, "reasoning": "message matches served scope" },
  "urgency":        { "score": 60, "weight": 0.2, "reasoning": "asks for an immediate call" }
}
```

`dropped_factors` JSON shape:

```json
[ { "name": "budget_signal", "reason": "AI provider unreachable" } ]
```

Write path: `ClassificationResult::create(...)`; on failure `Log::error` and continue (R3d). No updates, no soft deletes — retained for audit.

## Value object: Classification (enum)

Four-valued outcome set (FR-001). Backed enum `App\Enums\Classification`: `high`, `medium`, `low`, `disqualify`. Stored as its value string; mapped from the final score via `config('scoring.thresholds')` (R2).

## Scoring domain objects (non-persisted)

- `FactorVerdict` — a factor computing its value returns `{score: int (0-100), reasoning: string, meta: array}` (FR-002, R4).
- `FactorScore` — engine-internal: `{factor: string, score: int, weight: float, weighted: float, reasoning: string}`.
- `ClassificationOutcome` — engine result: `{classification: Classification, score: float, factorScores: FactorScore[], reasoning: string, droppedFactors: array}`.

## Relationships / lifecycle

- Factor Settings → independent singleton, read by every classification and by the admin API.
- Classification Result → independent append-only log; references nothing by FK (contact/message copied in for audit immutability).
- No state transitions: classification is a single pass with no follow-up states (FR-013).
- Score → classification lifecycle: `clamp(score to 0-100)` → one factor dropped/renormalized if failed → `Σ(score·weight)/Σ(weight)` → `threshold map` → `Classification` (empty catalog short-circuits to `low`/0.00).

## Storage provisioning

- Database `inquiry_handler` (+ `inquiry_handler_test`) created in `db/init.sql`, owner `rag`, matching the existing `rag`/`auth` pattern (R8).
- `inquiry-handler/.env`/compose `DB_*`: pgsql, host `db`, db `inquiry_handler`, user/pass `rag`/`rag`. Not present in any other service's env (FR-010, SC-006).
- Migrations portable (work on Postgres 16 and SQLite `:memory:` used by tests).