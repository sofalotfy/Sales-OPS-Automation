# Feature Specification: Company Size Factor

**Feature Branch**: `009-company-size-factor`

**Created**: 2026-09-15

**Status**: Draft

**Input**: User description: "make the first factor that would rate how big is the company; if the company can't be found rate it with score 0; follow the already-existing structure for factors. This service will need to estimate how big the company is by looking at its public data from the internet."

---

## Context

The triage engine (006) scores an inquiry by combining pluggable, weighted
factors. Before this feature the factor catalog was **empty**, so every run
degraded to `score 0.00 / low` (see `006-weighted-factor-classification`). This
is the **first factor** to populate that catalog (FR-004 "add a factor"): it is
registered and its weighted score drives classification (0 → `disqualify` when
it is the only factor and finds no size signal).

A "how big is the company" factor is the natural first signal for sales
triage: an inquiry from a large, well-known enterprise is typically more
valuable than one from an anonymous individual. The factor must estimate the
inquirer's company size from **public data on the internet** (revenue/headcount
tiers derived from public signals), following the exact structure the existing
factors already use (a `ScoreFactor` implementation registered in the factor
registry, weight-tunable via factor-settings).

Two constraints from the constitution shape this:

- **Human-in-the-loop (principle III)**: a company that **cannot be found** must
  NOT be guessed or fabricated. If the factor cannot locate the company from
  public data it MUST return score **0** (fail-safe, never a made-up size), and
  the engine drops/normalizes accordingly — never a fabricated value.
- **Contacts never sent to the AI (constitution/FR-004)**: the factor uses the
  `company_name` (and, when available, `country_region`) contact fields to look
  up the company in public internet data; it NEVER sends the email/phone/other
  contact data to the AI. Email/phone/company contact fields remain excluded
  from any AI call.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Score the Inquiry by Public Company Size (Priority: P1)

A sales operator submits an inquiry that names a company (e.g. "Example PLC").
The triage engine looks up that company's public size signals on the internet,
derives a 0–100 "company size" score, and includes it as the first entry in the
classification's factor breakdown. The operator sees the company-size score,
its per-factor reasoning, and its contribution to the final weighted result.

**Why this priority**: This is the whole feature — it is the first real factor
in the catalog, without which triage never differentiates any inquiry.

**Independent Test**: Submit an inquiry that contains a known public company
name; confirm the response includes a `company_size` factor with a numeric
0–100 score and a reasoning string, and that the final weighted score reflects
it according to its (default) weight.

**Acceptance Scenarios**:

1. **Given** an inquiry whose message/company name refers to a company that is
   findable in public internet data, **When** the inquiry is triaged, **Then**
   the response includes a `company_size` factor with a deterministic 0–100
   score, a `reasoning` string explaining the size estimate, and a
   `factor_scores` entry whose weighted contribution uses the factor's
   configured weight.
2. **Given** a company is found in public data, **When** the factor scores it,
   **Then** the score is symmetric/stable for the same company data (same
   signals → same score) and never exceeds 100.

---

### User Story 2 - Company Not Found → Score 0, No Fabrication (Priority: P1)

When the inquiry names a company that cannot be located in public internet
data (unknown/too small/name not indexed), the factor must NOT invent a size.
It returns score **0**, records `reasoning` stating the company could not be
found, and the engine handles the 0 exactly like any low-scoring factor (the
score still contributes via the weighted mean; it never errors the run).

**Why this priority**: This is the fail-safe mandated by the constitution —
fabricating a company size for an unfindable company would be a violation of
the human-in-the-loop / no-fabrication principle.

**Independent Test**: Submit an inquiry naming a clearly non-existent company
(e.g. "Acme Nowhere LLC 98765"); confirm the `company_size` factor returns
`0` with reasoning like "company not found in public data", and that triage
still returns `200` with a valid classification (no 5xx, no fabricated size).

**Acceptance Scenarios**:

1. **Given** an inquiry names a company that is not found in public internet
   data, **When** the factor runs, **Then** it returns score `0` with a
   reasoning string stating the company could not be found.
2. **Given** a company is not found, **When** the run completes, **Then** the
   HTTP response is still `200` with a valid classification and no fabricated
   size value anywhere in the payload.

---

### User Story 3 - Company Size Factor Uses Company Name & Country Only (Priority: P2)

The company-size lookup is scoped by `company_name` and (when present)
`country_region` from the inquiry's contact fields. No other contact field
(first/last/email/phone) is ever sent to the internet-data lookup or to the
AI.

**Why this priority**: Privacy/compliance (contacts never leave the service
except as needed) — correctness of scoring depends on the right identifiers,
but this is secondary to the two P1 behaviors above.

**Independent Test**: Inspect the factor's input handling (unit test): the
lookup receives only `company_name` + optional `country_region`; assert that
`first_name`/`last_name`/`email`/`phone_number` are absent from any outbound
request the factor ever makes.

**Acceptance Scenarios**:

1. **Given** an inquiry with contact fields, **When** the company-size factor
   performs its public-data lookup, **Then** the only inquiry fields used for
   the lookup are `company_name` and (optional) `country_region`.
2. **Given** the factor makes an outbound call, **When** the payload is
   inspected, **Then** it contains no `email`, `phone_number`, `first_name`, or
   `last_name` values.

---

## Edge Cases

- **Empty/blank `company_name`** (optional field): the factor must NOT fabricate
  a size. Missing company name → treat as "company not found" → score `0`,
  reasoning "no company name provided" (this keeps the no-fabrication rule when
  the form's optional company field is left blank).
- **Non-existent / too-small companies**: score `0`. This is not an error; it is
  a valid low score.
- **Upstream public-data lookup unavailable/fails**: the factor must degrade to
  score `0` with a clear reasoning note (matching how the engine has treated
  failed factors — FR-007 factors can be dropped, or return 0 defensively)
  rather than throw and 5xx the run. Never fabricate a size from a failed
  lookup.
- **Ambiguous/duplicate company names**: if multiple distinct companies share
  the name and public data is ambiguous, the factor must not guess a single
  best size — it returns score `0` with reasoning that the match was ambiguous
  (human can review), OR uses the `country_region` to disambiguate when it
  uniquely resolves. Chosen default: return `0` + "ambiguous company name"
  unless `country_region` uniquely disambiguates (documented assumption ASS-04).
- **Inquiry is a disqualify/low message** (e.g. "fix my washing machine"):
  company size may not be the driving factor, but the factor still computes
  whatever public signal it can for the named company; a `null`/missing company
  never blocks the run and never inflates the score.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST expose a `company_size` factor implemented as a
  `ScoreFactor` (per the existing factor interface in `006`) and registered in
  the factor registry so it appears in `/inquiry/triage` factor scores and in
  the admin factor-settings list.
- **FR-002**: The factor MUST produce a deterministic 0–100 score derived from
  the company's **public internet data** (revenue/headcount-tier signals) and
  MUST NOT exceed 100 or go below 0.
- **FR-003**: When the company **cannot be found** in public data (not indexed,
  non-existent, or `company_name` missing/blank), the factor MUST return score
  **0** with a reasoning string stating the company was not found. It MUST
  NEVER fabricate an estimated size.
- **FR-004**: The factor MUST use ONLY `company_name` (and optional
  `country_region`) from the inquiry's contact fields for the public-data
  lookup. It MUST NOT send `first_name`, `last_name`, `email`, or
  `phone_number` in any outbound lookup or AI call.
- **FR-005**: Under an unrecoverable public-data lookup failure, the factor
  MUST degrade to score `0` (with a reasoning note) rather than throw or
  fabricate — the triage response stays a `200` with a valid classification.
- **FR-006**: The factor MUST read its configured weight from the factor weight
  store (per the existing factor-settings flow) and contribute to the weighted
  mean exactly like every other registered factor; unknown/missing weight falls
  back to the default (matching the existing behavior).
- **FR-007**: RESOLVED (2026-09-19, implementation). The public data source is
  the **AI research agent's findings** (feature 010): the factor runs an AI call
  whose input is the inquirer's `company_name` (and optional `country_region`)
  plus the grounded `web_research.findings` (summary + fetched source URLs) from
  the completed research pass. No separate company-data/CRM API is consulted. The
  AI returns a strict JSON `{score: 0-100, size_band, employee_count, reasoning}`
  that the factor parses; unparseable/out-of-range/failed output degrades to
  score `0` with an honest reasoning note (FR-003). Contact fields are never sent
  (SC-003).

### Key Entities

- **Company Size Factor (ScoreFactor)**: a pluggable scoring factor (per the
  existing `ScoreFactor` interface) that computes a 0–100 size score from public
  company data. Carries a stable name (`company_size`), stored weight, score,
  and per-run reasoning.
- **Classification Result**: the append-only per-inquiry record; gains the
  new `company_size` factor score and its weighted contribution in the
  `factor_scores` breakdown. No schema change to the contact columns (these
  already exist from 008).

## Assumptions

- **ASS-01**: "Public data" means the **AI research agent's findings** (feature
  010) — the grounded summary and fetched source URLs produced by the research
  pass over public web pages. Resolved in the implementation (see FR-007);
  no separate provider is needed.
- **ASS-02**: A company is considered "found" only when public data yields a
  stable, non-ambiguous size signal; anything else (blank name, not indexed,
  ambiguous) → score `0`.
- **ASS-03**: Score 0 for "company not found" is a correct, expected outcome —
  not an error — and the run returns `200`.
- **ASS-04**: `country_region` is used only to disambiguate identical company
  names; it does not become a size signal itself.
- **ASS-05**: No new database schema is required: the factor's score flows into
  the existing `factor_scores`/`final_score` columns. Only code (factor +
  registry + settings wiring) changes.
- **ASS-06**: Because this is the only factor initially, its effective weight is
  the factor-settings default (1.0) until the dashboard tunes it.

## Success Criteria

- **SC-001**: 100% of triage runs with a findable company return a `company_size`
  factor score between 0 and 100 with a non-empty reasoning string.
- **SC-002**: 100% of triage runs where the company is not found return
  `company_size` score `0`, and the HTTP response is a `200` with a valid
  classification (never a 5xx, never a fabricated size).
- **SC-003**: 100% of outbound company-size lookups carry no email/phone/first/
  last-name contact data (asserted in tests).
- **SC-004**: The admin factor-settings page and the triage response both list
  `company_size` as a registered, tickable factor immediately after
  deployment (no new migration/restart of dependent steps).
