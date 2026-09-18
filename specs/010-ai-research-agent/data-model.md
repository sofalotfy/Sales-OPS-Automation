# Data Model: AI Research Agent for Company & Person Enrichment

**Feature**: 010-ai-research-agent · **Date**: 2026-09-18

This feature introduces **no new table and no migration**. It changes the shape
of data already stored in the existing `classification_results.web_research`
JSON column (feature: web-research step) and the in-memory entities that flow
through the agent pipeline. Existing columns are unchanged.

## Persisted entity: `classification_results` (unchanged columns; changed JSON shape)

Append-only log, one row per triage run. The web-research columns already exist
(added by `2026_09_16_000000_add_web_research_to_classification_results_table.php`).

| Column | Type | Nullable | Change |
|---|---|---|---|
| `web_research_outcome` | varchar(20) | yes | **unchanged** — `accept` / `decline` / `indeterminate` (step outcome) |
| `web_research_reason` | text | yes | **unchanged** — the step's reason |
| `web_research` | json | yes | **shape changed** — see below (no migration) |

### `web_research` JSON — before → after

The envelope (`outcome`, `reason`, `criteria`, `findings`) is preserved; only
`findings` changes from raw result lists to the agent's summary object, and raw
candidate results are no longer stored (spec Clarification Q1:C, FR-008/FR-010).

**Before**

```json
{
  "criteria": { "company": { "name": "Example Corp", "country_region": "United Kingdom" },
                "person":  { "first_name": "Jane", "last_name": "Doe", "company_context": "Example Corp" } },
  "findings": {
    "company": { "query": "Example Corp United Kingdom company", "summary": "…", "found": true, "results": [ { "title": "…", "url": "…", "snippet": "…" } ] },
    "person":  { "query": "Jane Doe Example Corp United Kingdom", "summary": "…", "found": true, "results": [ { "title": "…", "url": "…", "snippet": "…" } ] }
  }
}
```

**After**

```json
{
  "criteria": { "company": { "name": "Example Corp", "country_region": "United Kingdom" },
                "person":  { "first_name": "Jane", "last_name": "Doe", "company_context": "Example Corp" } },
  "findings": {
    "outcome": "completed",
    "summary": "Example Corp is a UK fintech; Jane Doe leads engineering there …",
    "sources": [
      { "title": "Example Corp — About", "url": "https://example.com/about" }
    ],
    "limitations": null,
    "uncertain": false
  },
  "audit": {
    "counts": { "obtained": 12, "kept": 2, "rejected": 10 },
    "kept": [ { "title": "Example Corp — About", "url": "https://example.com/about" } ],
    "rejected": [ { "title": "10 Best Companies to Work For", "url": "https://aggregator.example/list" } ],
    "filter_outcome": "ok",
    "filter_keep": [1, 5],
    "filter_reason": "Matches the target entity.",
    "rescue_used": false
  }
}
```

- `criteria` is byte-for-byte the contact-safe criteria built by
  `WebResearchService::criteria()` (only company name + country and person name +
  company context; never email/phone).
- `findings.outcome` ∈ `completed | partial | not_found | ambiguous` (the stored
  `indeterminate` case leaves `web_research_outcome=indeterminate` with an empty
  `findings`, matching today's fail-open rows).
- `findings.sources[].url` is always a URL that was actually fetched and used.
- `findings.uncertain` is `true` only when the profile came from the best-effort
  name-match rescue (few/confounded sources).
- `audit` (added later) is the candidate-filter snapshot ({counts, kept,
  rejected, filter_outcome, filter_keep, filter_reason, rescue_used}) that the
  dashboard renders so operators can verify filtering. Present when a run
  gathered ≥1 candidate; `[]` for zero-candidate or indeterminate runs; absent
  on rows written before the field existed.
- Raw candidate results (`results[]`) are intentionally not retained.

## Transient entities (in-memory, not persisted independently)

### Research Agent Run

One execution of the fixed pipeline for one inquiry. Carries the target, the
candidate set, the kept set, the fetched set, and the resulting summary.

| Field | Type | Notes |
|---|---|---|
| `criteria` | array | contact-safe lookup criteria (company, person, country) |
| `candidates` | Candidate Result[] | flattened from the provider payload |
| `kept` | Candidate Result[] | candidates the AI judged on-entity |
| `fetched` | Fetched Source[] | kept sources whose page content was retrieved |
| `result` | Research Result | outcome + summary + sources |
| `reason` | string | short human-readable reason for the outcome |

### Candidate Result

A public search hit considered by the agent. Not persisted after the run.

| Field | Type | Notes |
|---|---|---|
| `id` | int | 1-based index used in the filter contract |
| `section` | `company` \| `person` | which provider section it came from |
| `title` | string | page title |
| `url` | string | absolute http(s) URL |
| `snippet` | string | provider snippet (used for filtering input only) |
| `score` | float | provider relevance score (ordering only) |

### Fetched Source (Kept Source)

A kept candidate whose page content was fetched and used as grounding.

| Field | Type | Notes |
|---|---|---|
| `title` | string | page title, or the source title fallback |
| `url` | string | fetched URL |
| `text` | string | extracted, capped visible text (not persisted; only summary/sources are) |

### Research Result (value object `App\WebResearch\ResearchResult`)

| Field | Type | Notes |
|---|---|---|
| `outcome` | Research Outcome | see below |
| `summary` | string | visitor-safe, source-grounded profile, or an explicit not-found/ambiguous statement |
| `sources` | `{title, url}[]` | subset of fetched sources; re-mapped against the fetch set |
| `limitations` | ?string | what could not be established |

### Research Outcome (`App\WebResearch\ResearchOutcome`)

| Value | Meaning | Step verdict |
|---|---|---|
| `completed` | summary produced from fetched sources | accept |
| `partial` | summary produced, but some kept sources failed to fetch | accept |
| `not_found` | no candidate was judged to be about the target | accept (statement) |
| `ambiguous` | target name matches multiple distinct entities | accept (statement) |
| `indeterminate` | provider/AI/fetch step failed, or no fetched content | indeterminate (fail-open) |

## State transitions

```text
provider payload ──► candidates ──(AI filter)──► kept ──(fetch)──► fetched
                                                                    │
                                                                    ▼
                                            ResearchResult(outcome, summary, sources)
                                                                    │
                              accept ────────────────────────────────┘
                                └─► WebResearchVerdict::accept(findings)
                                    ├─ context.web_research (response)
                                    └─ classification_results.web_research (store)

any failure ──► WebResearchVerdict::indeterminate(...)  (fail-open; flow continues)
provider `decline` ──► WebResearchVerdict::decline(...)  (unchanged; short-circuits)
```

## Validation rules (from requirements)

- Only `company_name`, person name, and optional `country_region` are ever part
  of `criteria`; email/phone are never present (FR-001, SC-007).
- `findings.sources[].url` MUST be a URL that was actually fetched (FR-004/FR-005,
  SC-001).
- `not_found`/`ambiguous`/`indeterminate` MUST NOT contain fabricated facts
  (FR-006, SC-003).
- Candidate/fetched/page content is data only; it can never alter agent
  instructions (FR-009).
- Budgets (`max_candidates`, `max_sources`, `fetch_*`, `step_timeout`) bound every
  run (FR-013, SC-005).
