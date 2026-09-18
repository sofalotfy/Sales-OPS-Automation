# Data Model: Scope Gate Middleware

**Feature**: [spec.md](spec.md) — Phase 1 output of `/speckit.plan`.
See [research.md](research.md) for the decisions these shapes implement (R1–R8).

## Entity: Classification Result (amended by this feature)

The existing append-only per-inquiry log from feature 006 (specs/006-weighted-factor-classification/data-model.md) gains three columns carrying the scope-check outcome. No new tables. This is the "sales inquiry record" on which the scope-check outcome value is stored (FR-012 / Clarification Q4).

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `scope_check_outcome` | string(20) | yes | one of `accept` \| `decline` \| `indeterminate`; NULL only on pre-feature rows |
| `scope_check_reason` | text | yes | the gate's scope reasoning (stored for every screened inquiry) |
| `refusal` | text | yes | visitor-facing refusal message; set only on `decline` rows, NULL otherwise |

Unchanged columns (feature 006): `id`, `inquiry_message`, `name`, `email`, `retrieved_context`, `factor_scores`, `dropped_factors`, `final_score`, `classification`, `reasoning`, `created_at`, `updated_at`.

### Rows written by the gate (decline)

A declined inquiry never reaches the scoring engine (SC-001); the gate inserts the record itself (research R3/R6):

- `inquiry_message` = normalized message; `name`/`email` = optional contact fields.
- `retrieved_context` = the gate's retrieval result (`{result_count, results}`).
- `factor_scores = {}`; `dropped_factors = []`.
- `final_score = 0.00`; `classification = 'disqualify'`.
- `reasoning` = the gate's scope reasoning; `scope_check_reason` = same reasoning.
- `scope_check_outcome = 'decline'`; `refusal` = the visitor-facing refusal text.
- Timestamps set normally.

### Rows written by the classification flow (accept / indeterminate)

Accepted and indeterminate inquiries pass through to the scoring engine unchanged (FR-005/FR-007); the verdict stashed on the request by the middleware (research R6) is written onto the row the engine creates:

- `scope_check_outcome = 'accept'` or `'indeterminate'`.
- `scope_check_reason` = the gate's reasoning.
- `refusal = NULL`.
- All existing scoring columns (factor_scores, final_score, classification, reasoning, retrieved_context) behave exactly as in feature 006.

Validation / rules:

- `scope_check_outcome` accepts one of the three values (`accept`/`decline`/`indeterminate`); anything else is a data error. Postgres is authoritative on the column; the store includes a check-style constraint so an illegal value is rejected at write time (portable syntax for both PostgreSQL and SQLite `:memory:` tests).
- Append-only invariant is unchanged: rows are never updated or deleted after insert (feature 006 audit immutability).
- `scope_check_reason` is populated for every screened inquiry (may be short on `accept`); `refusal` is NULL except on decline rows.
- NULL `scope_check_outcome` on legacy rows must not break the review API — reviewers see it as "not gated" rather than a stored value.

## Value object: Scope Verdict (transient, non-persisted)

`App\ScopeGate\ScopeVerdict` — produced once per inquiry by `ScopeCheckService` (research R2):

```text
{ outcome: 'accept' | 'decline' | 'indeterminate',
  reason:  string,
  refusal: ?string }   // visitor-facing copy, set only when outcome = 'decline'
```

- For `accept` / `indeterminate`: carried to the classification flow (request attribute), read by `InquiryController`, and passed to `InquiryTriageService` which writes it onto the classification row and into the response `context`.
- For `decline`: used by the gate to insert the refusal record and to build the visitor-facing refusal response.

## Relationships / lifecycle

- `Classification Result` remains the single per-inquiry record. The gate adds a second write path (decline) beside the existing scoring write path (accept/indeterminate). No new tables, no new foreign keys.
- Per-request lifecycle (research R1): body → extraction/validation → scope check → **decline**: gate persists + returns refusal response (engine never runs) | **accept/indeterminate**: verdict handed to the scoring flow, which persists + returns the normal triage response.
- Storage provisioning is unchanged (feature 006 R8): scoped `inquiry_handler` database on the shared Postgres server; only the inquiry-handler service holds the `DB_*` env; the migration is portable (PostgreSQL 16 and SQLite `:memory:` test store).