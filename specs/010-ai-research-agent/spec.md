# Feature Specification: AI Research Agent for Company & Person Enrichment

**Feature Directory**: `010-ai-research-agent`

**Feature Branch**: `010-ai-research-agent`

**Created**: 2026-09-18

**Status**: Draft

**Input**: User description: "i want to make an ai agent that would take the company name and person name then use the research classes to get some results filter them for what he needs and then fetch their data then he summarize the results"

## Clarifications

### Session 2026-09-18

- Q: Should the AI agent's filtered and summarized profile replace the current raw web-research findings, or be added alongside them? → A: Replace in the output and drop the raw candidate results entirely — only the agent's kept sources, summary, and outcome are surfaced and recorded.
- Q: What should the agent "fetch" after filtering? → A: Fetch the bounded content of each kept source page (page-content extraction, not snippets only), so the summary is grounded in the actual source content.
- Q: How autonomous should the agent be in v1? → A: A fixed pipeline in v1 (gather → AI filter → fetch → summarize); the bounded follow-up-search loop is explicitly out of scope and may be designed later.
- Q: For later audit, what should the persisted record contain beyond the lookup criteria, the kept sources, and the final summary? → A: Kept-only — criteria, kept sources (title/url), summary, and outcome; no per-candidate kept/discarded trail and no fetched page text (US5 wording revised to match FR-010).
- Q: When one supplied entity is found but the other is not, which agent outcome should the run report? → A: `completed` — a single summary covers the found entity and explicitly names the entity that could not be established; nothing is fabricated.
- Q: How specific should the person-privacy restriction in the summarizer be? → A: A general instruction to collect only public professional information and never infer private/sensitive attributes — no enumerated denylist in v1.
- Q: On a budget/timeout hit after gathering some usable content, should the run report `partial` or `indeterminate`? → A: `partial` when a summary can still be produced from the fetched sources; `indeterminate` only when nothing usable was gathered.
- Q: When a name matches several distinct entities and the country still leaves more than one match, what should the agent do? → A: Attempt to match the company by name (resolve to the best company match); if it still cannot resolve to a single entity, report `ambiguous` rather than merging or inventing.

## Context

The inquiry-handler already runs a pre-classification web-research step: it builds
contact-safe lookup criteria (company name + country, person name + company
context), asks a search provider for public results about the inquirer's company
and person, and stores the raw result lists verbatim. That raw list is noisy — a
generic company-name search returns aggregator and competitor pages that mention
many different companies — and it carries no synthesis: a human still has to read
a pile of snippets to learn anything about the one company named in the inquiry.

This feature adds an **AI research agent** that turns those raw hits into a
filtered, source-grounded profile of the single company/person the inquiry names:
it gathers candidate results with the existing research classes, uses AI to keep
only the results that are actually about the named entity, fetches the content of
the kept sources, and summarizes what it found with citations. The agent runs on
the same pre-classification enrichment path as today's web research and stays
advisory (constitution principle III): it never fabricates a fact and never
blocks the inquiry.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - One Filtered Research Summary for the Named Company/Person (Priority: P1)

A sales operator submits an inquiry that names a company and a person. The AI
research agent gathers candidate public results, keeps only those actually about
the named company/person, reads the kept sources, and returns one concise summary
describing the company and the person, together with the sources it used. The
operator sees a coherent, on-target profile instead of a raw list mixing many
companies.

**Why this priority**: This is the core value the user asked for — the agent that
filters "for what he needs" and then summarizes. Without it there is no feature.

**Independent Test**: Submit an inquiry naming a findable company + person and
confirm the enrichment result contains a summary that references only the named
company/person and lists the sources it was grounded in.

**Acceptance Scenarios**:

1. **Given** an inquiry naming company X and person P, **When** the agent runs,
   **Then** every kept source and the summary are about X/P, and no other
   company's data is presented as X's or P's.
2. **Given** candidate results that include aggregator pages listing many
   companies, **When** the agent filters, **Then** those results are excluded (or
   reduced to the X-relevant portion) and the summary never attributes another
   company's facts to X.
3. **Given** the same company + person inputs, **When** the agent runs twice,
   **Then** it produces an equivalent summary from the same public data — no
   random fabrication.

---

### User Story 2 - The Agent Fetches Source Data and Grounds the Summary in It (Priority: P1)

The agent does not stop at search snippets: it fetches the content of the kept
sources and bases the summary on that content, citing which source supports each
claim.

**Why this priority**: The user explicitly asked to "fetch their data then
summarize"; a summary built only from snippets risks fabrication (constitution
III).

**Independent Test**: Point the agent at a kept source whose page content differs
from its snippet and confirm the summary reflects the fetched content and names
that source.

**Acceptance Scenarios**:

1. **Given** filtered sources, **When** the agent runs, **Then** it fetches the
   content of the kept sources (up to a configured limit) and the summary is
   grounded in that content.
2. **Given** a kept source that fails to fetch or times out, **When** the agent
   runs, **Then** that source is skipped with no error and the summary still
   completes from the sources that did fetch.
3. **Given** a completed summary, **When** an operator reads it, **Then** it
   identifies the sources supporting its claims.

---

### User Story 3 - Honest Handling of Missing, Ambiguous, or Unfindable Entities (Priority: P2)

Not every inquiry names a findable company/person, and some names are shared by
many distinct companies. The agent must not guess or merge entities; it states
what it could and could not establish.

**Why this priority**: Constitution principle III (no fabrication) and basic
correctness — a wrong enrichment is worse than an honest "not found".

**Independent Test**: Submit an inquiry with a clearly non-existent company and
confirm the result states the company/person could not be found, contains no
invented facts, and the inquiry still completes.

**Acceptance Scenarios**:

1. **Given** no company name (or a blank one), **When** the agent runs, **Then**
   it does not invent a company and proceeds with whatever identifiers exist.
2. **Given** a name that matches multiple distinct companies, **When** the agent
   runs, **Then** it attempts to match the company by name and, if it still cannot
   resolve to a single entity, reports the ambiguity (naming the candidates)
   rather than merging or inventing; when a country uniquely resolves it, it
   proceeds.
3. **Given** a company/person not found in public data, **When** the agent runs,
   **Then** the result says so and contains no fabricated details.
4. **Given** only one of the two named entities can be found, **When** the agent
   runs, **Then** the outcome is `completed`, the summary profiles the found
   entity, and it explicitly names the entity that could not be established.

---

### User Story 4 - Agent Failures Never Break or Block the Inquiry (Priority: P2)

The agent is a chain of search calls, AI calls, and page fetches, any of which can
fail. When the agent cannot complete, the inquiry flow continues — the agent is
enrichment, not a gate.

**Why this priority**: Reliability and constitution III (never silently drop an
inquiry).

**Independent Test**: Force the search provider and/or the AI to fail and confirm
the inquiry still completes and no partial or fabricated summary is presented as
complete.

**Acceptance Scenarios**:

1. **Given** the search provider or the AI is unavailable, **When** an inquiry is
   submitted, **Then** the enrichment degrades (empty/indeterminate) and the
   inquiry still completes with a valid response.
2. **Given** the agent times out or exceeds its configured budget, **When** it
   runs, **Then** it stops at the budget and returns what it legitimately
   gathered, clearly marked as `partial` when a summary is possible or
   `indeterminate` when nothing usable was gathered — never fabricated.

---

### User Story 5 - Every Agent Run Is Auditable (Priority: P3)

Each run records the criteria used, the kept sources, and the final summary,
together with the outcome, so a human can review how a conclusion was reached.
Raw candidate results and fetched page text are not retained (FR-010).

**Why this priority**: Constitution III/IV — governance and observability; the
advisory result is only trustworthy if its grounding is inspectable.

   **Independent Test**: Run the agent, then retrieve the persisted record and
   confirm it contains the criteria, the kept sources, the summary, and the outcome.

**Acceptance Scenarios**:

1. **Given** a completed agent run, **When** a reviewer looks it up later,
   **Then** they can see the criteria, the kept sources, the summary,
   and the outcome.

---

### Edge Cases

- **Blank/missing company name** — person-only research; no company is invented.
- **Blank/missing person name** — company-only research; no person is invented.
- **Common-word names** — a company or person name that is an ordinary word must
  not match unrelated entities.
- **Aggregator/directory pages** listing many companies — must not leak other
  companies' facts into the summary.
- **Very large source pages** — only a bounded portion is fetched/kept.
- **Prompt-injection text inside a fetched page** — treated strictly as data; it
  cannot change the agent's instructions or output contract.
- **Non-Latin names, punctuation, legal suffixes** (Ltd, Inc, GmbH) — matching is
  tolerant of these variations.
- **Search provider returns zero results** — the agent reports nothing found, with
  no fabrication.
- **Only one of the two entities is found** — the run reports `completed` and the
  summary names the missing company or person without inventing details.
- **AI returns malformed output** — treated as a failure (indeterminate/fail-open)
  and never coerced into a summary.
- **Contact fields** — email and phone are never sent to the search provider or
  the AI.
- **Cost/time** — the number of searches, fetches, and AI calls per run is
  bounded by configuration; an over-budget run degrades rather than hanging.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The agent SHALL accept a company name and a person name (plus an
  optional country/region) as its inputs, and SHALL use no other inquiry field for
  any outbound lookup or AI call.
- **FR-002**: The agent SHALL gather candidate public results using the existing
  research/search capability.
- **FR-003**: The agent SHALL use AI to filter the candidate results down to those
  that are about the named company/person, discarding irrelevant and multi-company
  results.
- **FR-004**: The agent SHALL fetch the content of the kept sources, up to a
  configured maximum, and SHALL base its summary on that fetched content.
- **FR-005**: The agent SHALL produce a single concise summary of the company and
  the person, identifying the sources that support it.
- **FR-006**: When the company/person cannot be found, or the name is ambiguous,
  the agent SHALL state so and SHALL NOT fabricate facts. When several entities
  match, the agent SHALL first attempt to resolve the company by name and SHALL
  report `ambiguous` if a single entity still cannot be determined. When only one
  of the two supplied entities can be established, the run SHALL still report
  `completed` and the summary SHALL explicitly name the entity that could not be
  established.
- **FR-007**: When any step fails or the configured budget is exceeded, the agent
  SHALL degrade gracefully and SHALL NOT block or error the inquiry — the existing
  flow completes. It SHALL report `partial` when a summary can still be produced
  from the sources fetched, and `indeterminate` only when nothing usable was
  gathered.
- **FR-008**: The agent SHALL record each run — the lookup criteria, the kept
  sources (title/url), the final summary, and the outcome — for later human
  review. Raw candidate results and fetched page text are discarded and are NOT
  retained; the record holds no per-candidate kept/discarded trail (per the
  output clarification above).
- **FR-009**: The inquiry content and all fetched page content SHALL be treated
  strictly as data; they can never alter the agent's instructions or output
  contract.
- **FR-010**: The agent's filtered, source-grounded summary SHALL replace the
  current raw web-research findings in the output; the raw candidate result lists
  SHALL NOT be surfaced or retained, and only the kept sources + summary +
  outcome are carried forward.
- **FR-011**: For each kept source, the agent SHALL fetch the source page's
  bounded content (not snippets alone) and SHALL base its summary on that fetched
  content.
- **FR-012**: In v1 the agent SHALL run as a fixed pipeline: gather candidate
  results → AI-filter to the named entity → fetch kept-source content →
  summarize. Bounded follow-up searches are OUT OF SCOPE for v1 and may be
  designed as a later extension.
- **FR-013**: The number of searches, fetches, and AI calls per run SHALL be
  bounded by configuration, and credentials SHALL come from configuration and
  never be logged or committed to source control.
- **FR-014**: For identical inputs and unchanged public data, the agent SHALL
  produce an equivalent summary.

### Key Entities *(include if feature involves data)*

- **Research Agent Run**: one execution for an inquiry — lookup criteria (company,
  person, country), kept sources (title/url), summary, and outcome.
  (Raw candidate results and fetched page text are discarded and not retained.)
- **Candidate Result**: a public search hit considered by the agent (title, url,
  snippet, score).
- **Kept Source**: a candidate the AI judged to be about the named entity, whose
  content was fetched and used as grounding.
- **Research Summary**: the grounded, source-cited profile of the company/person,
  or an explicit "not found / ambiguous" statement.
- **Agent Outcome**: **completed**, **partial**, **not_found**, **ambiguous**, or
  **indeterminate**, recorded on the inquiry.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of runs with a findable company/person return a summary whose
  claims are supported by the cited fetched sources.
- **SC-002**: 0% of summaries attribute another company's facts to the named
  company (no cross-entity leakage) across the acceptance test set.
- **SC-003**: 100% of unfindable/ambiguous cases return an explicit statement with
  no fabricated facts, and the inquiry still returns HTTP 200.
- **SC-004**: 100% of provider/AI/fetch failures degrade fail-open — the inquiry
  completes and no fabricated summary is shown.
- **SC-005**: Every run stays within its configured search/fetch/time budget; a run
  that would exceed it returns a partial or indeterminate result rather than
  hanging.
- **SC-006**: 100% of runs are retrievable later with their criteria, kept sources,
  and summary.
- **SC-007**: 0% of outbound agent calls contain email, phone, or any other
  non-permitted contact field (asserted by test).

## Assumptions

- The existing research classes and search provider are reused; no new external
  data source is introduced in v1.
- The agent's output is advisory context for the human reviewer, not an automated
  decision (constitution principle III).
- Only public internet data is used; email and phone are never part of any lookup.
- The agent runs on the same pre-classification public inquiry path as today's
  web research; operator/admin endpoints are unaffected.
- "Person" means the inquirer's first/last name as given; the agent may look up
  their public professional profile but must not infer private or sensitive
  attributes. In v1 this is enforced by a general instruction in the fixed
  summarize prompt (no enumerated denylist — see Clarifications).
- The v1 agent is a fixed pipeline (gather → AI filter → fetch → summarize),
  fetching the bounded content of the kept sources; bounded follow-up searches
  and feeding the summary into scoring factors (e.g. spec 009's company-size
  factor) are out of scope (see Clarifications; FR-012).

## Deviations from Constitution (recorded)

- No deviation. The feature is additive within the inquiry-handler service and
  remains HTTP-only. Principle III (no fabrication; fail-open) and principle V
  (smallest version that satisfies the spec) are honored.
- **Recorded contract change**: per the Clarifications, the agent's summary
  replaces the existing raw web-research findings and raw candidate results are
  dropped (FR-010, FR-008). This intentionally changes the current
  `web_research.findings` contract; the plan must update the response/record
  shape and the affected tests. Auditability is reduced by design (kept sources
  and summary remain, raw candidates do not).
