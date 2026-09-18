# Research: AI Research Agent for Company & Person Enrichment

**Feature**: [spec.md](spec.md) — Phase 0 output of `/speckit.plan`.

This document resolves the design decisions needed before Phase 1. Format per
artifact: Decision, Rationale, Alternatives considered.

## R1. Agent placement, pipeline, and seams

- **Decision**: Introduce `App\WebResearch\ResearchAgent` inside the existing
  inquiry-handler and slot it into the current web-research step. The pipeline is
  **fixed** (spec Clarification Q3:C, FR-012):
  1. **Gather** — `WebResearchService` still builds the contact-safe `criteria`
     and calls the injected `WebResearchProvider` (today `TavilyResearchProvider`)
     to get candidate results. `WebResearchService` keeps ownership of the
     provider `decline` short-circuit.
  2. **Filter** — `ResearchAgent` sends the flattened candidates + the target
     entity to `AiCallingService::complete()` and keeps only the candidates the
     AI judges to be about the named company/person (R2).
  3. **Fetch** — `PageFetcher` retrieves the bounded content of the kept source
     URLs (R3).
  4. **Summarize** — `ResearchAgent` sends the target + fetched documents to
     `AiCallingService::complete()` and produces a source-cited summary (R4).
  `WebResearchService::run()` maps the `ResearchResult` onto the existing
  `WebResearchVerdict` (`accept` carrying the new findings, or `indeterminate`
  fail-open).
- **Rationale**: The web-research step already owns criteria building, the
  provider boundary, the decline path, and fail-open mapping; the agent is a new
  *inner* stage, so the middleware/controller/persistence plumbing is untouched
  apart from the findings shape. Keeping `WebResearchProvider` as the gather
  boundary preserves the privacy contract and existing tests. A fixed pipeline
  satisfies the spec and constitution principle V.
- **Alternatives considered**: a separate service (rejected — spec assumption:
  reuse the existing step; no new service); true agentic loop with follow-up
  searches (rejected — spec Clarification Q3:C places it out of scope);
  building the agent into `TavilyResearchProvider` (rejected — filtering/fetching
  are provider-independent domain behavior, not search-provider behavior).

## R2. AI filter contract and ambiguity handling

- **Decision**: One JSON-mode `AiCallingService::complete()` call. The **system**
  prompt is a fixed constant instructing the model to select only candidates that
  are about the target company *and* (when given) the target person, and to report
  `not_found` or `ambiguous` honestly. The **user** message carries the target and
  a JSON list of candidates `[{id, title, url, snippet}]` inside data blocks. The
  required JSON output is:

  ```json
  {
    "outcome": "ok | not_found | ambiguous",
    "keep": [1, 3],
    "reason": "short explanation"
  }
  ```

  `keep` holds candidate ids; ids not valid for the supplied list are ignored.
  If the call fails / returns unusable JSON → the agent returns `indeterminate`
  (fail-open). If `outcome=not_found` or `keep` is empty → `not_found`. If
  `outcome=ambiguous` → `ambiguous`. Both `not_found` and `ambiguous` still
  produce a visitor-safe summary statement and are `accept` at the step level
  (they are not refuses; only the provider's own `decline` refuses).
- **Rationale**: One small deterministic call satisfies FR-003 and is easy to
  unit-test. Explicit `not_found`/`ambiguous` outcomes realize FR-006 / Story 3
  without fabricating. Treating the candidates as data blocks preserves the
  injection safety already established for the scope gate (FR-009).
- **Alternatives considered**: String/regex post-filtering (rejected by the user
  in favor of an AI filter); a separate per-candidate classification call
  (rejected — N AI calls for little gain); free-text selection (rejected —
  unparseable, nondeterministic).

## R3. Bounded page fetch and text extraction

- **Decision**: New `App\WebResearch\PageFetcher` using the Laravel HTTP client:
  - accept only `http`/`https` URLs; no redirects to other schemes;
  - `timeout` = `web_research.fetch_timeout` (default 8 s), follow a small
    number of redirects, `Accept: text/html`;
  - require a 2xx response and an HTML/text content type, otherwise skip;
  - stream/cap the body at `web_research.fetch_max_bytes` (default 200 KB);
  - strip `<script>`, `<style>`, `<noscript>`, HTML comments and tags, decode
    entities, collapse whitespace, and cap the extracted text at
    `web_research.source_max_chars` (default 8 000);
  - return `null` on any failure (timeout, non-2xx, wrong type, oversize); the
    agent simply skips that source (FR-004, Story 2 acceptance 2).
- **Rationale**: Keeps the grounding real (page content, not snippets) while
  bounding cost/latency/memory (FR-013, SC-005). No new Composer package —
  `strip_tags` + a small script/style scrub is sufficient for summarization
  input; a full readability parser is speculative (principle V). Fetched content
  is data only and is never interpreted as instructions (FR-009).
- **Alternatives considered**: `symfony/dom-crawler` / readability library
  (rejected — new dependency and heavier than needed for v1); using snippets
  only (rejected by spec Clarification Q2:A); trusting the Tavily `raw_content`
  field (rejected — provider-specific and not guaranteed present).

## R4. Summarization contract and grounding

- **Decision**: One JSON-mode `complete()` call. Fixed **system** prompt: produce
  a concise neutral profile of the company and person **using only the supplied
  fetched documents**; never invent facts; cite the document(s) each claim comes
  from; if the documents do not support a fact, omit it. **User** message carries
  the target and the fetched documents as data blocks. Required JSON output:

  ```json
  {
    "summary": "string",
    "sources": [{"title": "string", "url": "string"}],
    "limitations": "string|null"
  }
  ```

  The agent only accepts `sources` whose URLs were actually fetched (it re-maps
  against the fetch set), so the record cannot cite an unfetched page. An
  empty/non-string `summary` → `indeterminate` (fail-open).
- **Rationale**: Satisfies FR-005 and SC-001/SC-002 (grounded, no cross-entity
  leakage). Re-mapping sources against the fetched set is a cheap, deterministic
  guard against hallucinated citations. `limitations` gives the model an honest
  place for what it could not establish (reinforces FR-006).
- **Alternatives considered**: free-text summary (rejected — unparseable, no
  citation structure); letting the model choose arbitrary citations (rejected —
  unverifiable); deterministic stitched snippets (rejected — the user explicitly
  asked the AI to summarize and fetch).

## R5. Outcome model and fail-open mapping

- **Decision**: New enum `ResearchOutcome`
  (`completed | partial | not_found | ambiguous | indeterminate`) and value object
  `ResearchResult` (`outcome`, `summary`, `sources`, `reason`). Mapping:

  | Condition | Research outcome | Step verdict |
  |---|---|---|
  | ≥1 fetched source + non-empty summary, all kept sources fetched | `completed` | accept |
  | summary produced but some kept sources failed to fetch | `partial` | accept |
  | filter says not_found / zero kept candidates | `not_found` | accept (statement) |
  | filter says ambiguous | `ambiguous` | accept (statement) |
  | provider unavailable / no candidates / filter call fails / no fetch succeeded / summarize fails | `indeterminate` | **indeterminate** (fail-open, FR-007) |

- **Rationale**: Mirrors the established `WebResearchVerdict`/`ScopeVerdict`
  discipline and keeps every degradation safe. `not_found`/`ambiguous` are honest
  completed research (accept), while infrastructure failure is `indeterminate`.
  The existing `web_research_outcome` DB value set (`accept|decline|indeterminate`)
  is unchanged; the finer research outcome lives inside the `web_research` JSON.
- **Alternatives considered**: treating `not_found` as `indeterminate` (rejected —
  it is a determinate, meaningful result and would hide it); adding new values to
  the DB check constraint (rejected — needs a migration and widens the step
  outcome beyond the 007 contract).

## R6. Findings shape, response, and persistence

- **Decision**: Replace the raw `company`/`person` result lists with a single
  findings object (spec Clarification Q1:C). `WebResearchVerdict->research`
  becomes:

  ```json
  {
    "outcome": "completed",
    "summary": "…",
    "sources": [{ "title": "…", "url": "…" }],
    "limitations": null
  }
  ```

  `context.web_research` (response) and the persisted `web_research` JSON keep
  their existing envelope:

  ```json
  {
    "outcome": "accept",
    "reason": "Web research completed.",
    "criteria": { "company": {...}, "person": {...} },
    "findings": { "outcome": "completed", "summary": "…", "sources": [...], "limitations": null }
  }
  ```
  Raw candidate results are **not** retained anywhere (FR-008/FR-010). No column
  or migration is added — `web_research` is already a `json` column.
- **Rationale**: Delivers the user's ask (summary, not a noisy list) with the
  smallest schema footprint. The step-level `outcome`/`reason`/`criteria` envelope
  is untouched, so the audit and review paths keep working.
- **Alternatives considered**: adding dedicated columns for the summary
  (rejected — unnecessary; JSON already holds it); keeping raw candidates for
  audit (rejected explicitly by Clarification Q1:C); a new endpoint (rejected —
  the summary is enrichment context for the existing record).

## R7. Configuration and secrets

- **Decision**: Extend `config/web_research.php` (env-driven, FR-013):

  | Key | Env | Default |
  |---|---|---|
  | `max_candidates` | `WEB_RESEARCH_MAX_CANDIDATES` | 12 |
  | `max_sources` | `WEB_RESEARCH_MAX_SOURCES` | 5 |
  | `fetch_timeout` | `WEB_RESEARCH_FETCH_TIMEOUT` | 8 |
  | `fetch_max_bytes` | `WEB_RESEARCH_FETCH_MAX_BYTES` | 200000 |
  | `source_max_chars` | `WEB_RESEARCH_SOURCE_MAX_CHARS` | 8000 |
  | `summary_max_input_chars` | `WEB_RESEARCH_SUMMARY_MAX_INPUT_CHARS` | 24000 |
  | `step_timeout` | `WEB_RESEARCH_STEP_TIMEOUT` | 25 |

  The existing `enabled`, `provider`, `services.tavily.*`, and `services.zai.*`
  knobs are reused; no new secret is introduced. `.env.example` documents all new
  keys with empty/sane defaults.
- **Rationale**: FR-013 and SC-005 require bounded work; env-driven knobs keep
  the budget tunable without a deploy and let tests pin tiny values. Reusing the
  existing keys keeps secrets to the two providers already in use.
- **Alternatives considered**: hard-coded constants (rejected — not tunable);
  a new config file (rejected — same subsystem, keep it together).

## R8. Testing strategy

- **Decision**: PHPUnit on the existing suite (SQLite `:memory:`,
  `RefreshDatabase`, `Http::fake()` / `UpstreamStubs`).
  - **Unit `ResearchAgentTest`** — mock `AiCallingService` with
    `$this->mock(AiCallingService::class)` returning canned filter/summarize
    arrays per call; bind a fake `PageFetcher` returning canned pages. Cover:
    keep/drop filtering, `not_found`, `ambiguous`, `completed` vs `partial` (some
    fetches fail), filter-call failure → `indeterminate`, summarize failure →
    `indeterminate`, citations re-mapped to fetched URLs only, no candidates → no
    AI call, entity/data-block separation (target/candidates only in the user
    role).
  - **Unit `PageFetcherTest`** — `Http::fake()`: HTML scrubbed to text and
    capped; non-2xx / timeout / non-HTML / oversized → `null`; only http(s)
    fetched.
  - **Unit `WebResearchServiceTest`** — updated for the new constructor
    (`WebResearchProvider` + `ResearchAgent`); criteria/privacy tests unchanged;
    provider decline still short-circuits; agent `indeterminate` → fail-open
    verdict; agent result → findings shape.
  - **Feature `WebResearchMiddlewareTest`** — bind a fake `ResearchAgent` (or a
    fake provider producing a `completed` result) so the middleware is tested for
    the new response/persistence shape; keep the existing decline, fail-open,
    invalid-payload, and kill-switch cases. Add an assertion that raw
    `company`/`person` result lists no longer appear.
  - **Feature `ClassificationResultsAdminTest`** — update the seeded
    `web_research.findings` shape to the new summary object.
  - **`TavilyResearchProviderTest`** — unchanged (the provider still gathers raw
    candidates; filtering is downstream).
- **Rationale**: Mirrors the scope-gate/AI testing approach (faked HTTP, no live
  providers, no Postgres). Mocking `AiCallingService` keeps the agent tests
  deterministic and avoids brittle Z.AI response sequencing inside feature tests.
- **Alternatives considered**: hitting the real Z.AI/Tavily in tests (rejected —
  nondeterministic, key-dependent); only live-stack validation (kept in
  quickstart as a manual check).

## R9. Constitution re-check (post-design)

- **Decision**: Re-checked after Phase-1 design; no violations. I. no new service,
  HTTP-only consumers. II. no new service; pre-existing Laravel deviation carried
  forward. III. no fabrication (`not_found`/`ambiguous`/`indeterminate`),
  fail-open, nothing dropped. IV. all agent state persisted on the existing
  `classification_results` row. V. fixed pipeline, no new dependency/data
  source/table, reuse of provider + AI caller + HTTP client.
- **Rationale**: The only new surface is three small classes in one namespace
  plus a config section; everything else is additive.
- **Alternatives considered**: n/a.
