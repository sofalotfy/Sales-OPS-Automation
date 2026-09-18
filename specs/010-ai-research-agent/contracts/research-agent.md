# Contract: AI Research Agent (internal pipeline + provider boundary)

**Contract version**: 1.1 · **Service**: inquiry-handler · **Feature**: [010-ai-research-agent](../spec.md)

This is an internal contract for the fixed research pipeline that replaces the
raw web-research findings. It documents the AI JSON contracts, the page-fetch
boundary, and the result shape the rest of the step consumes. It does not
introduce a public endpoint; the public surface is covered by
[inquiry-web.md](inquiry-web.md).

## Pipeline

`App\WebResearch\WebResearchService::run()`:

```text
criteria(criteria-safe) ─► WebResearchProvider::research()  ─► candidates
                          (provider `decline` short-circuits, unchanged)
                                     │
                                     ▼
                          App\WebResearch\ResearchAgent::research(criteria, payload)
                                     │
            1. filter   (AI)  ──► kept candidate ids
            2. fetch    (HTTP) ─► fetched source content
            3. summarize (AI) ──► ResearchResult
                                     │
                                     ▼
                WebResearchVerdict::accept(findings) | indeterminate(...)
```

## 1. Candidate gathering (unchanged provider boundary)

`App\WebResearch\WebResearchProvider::research(array $criteria): array` — the
existing contract. Returns `company`/`person` sections (each with a `results`
list) and optionally a `decline` block. `WebResearchService` still owns the
`decline` short-circuit and the fail-open on a thrown/non-array payload. The
agent flattens the sections' `results` into Candidate Results (R1/data-model),
capped at `web_research.max_candidates`.

## 2. AI filter call

One `App\Services\AiCallingService::complete(string $system, string $user)` call.

- `system` — **fixed constant** in `ResearchAgent` (never request-influenced).
  Instructs: select only candidates about the target company *and* (when given)
  the target person; do not select aggregator/competitor pages that merely list
  the target among others; report `not_found` or `ambiguous` honestly; output
  strict JSON.
- `user` — the target and the candidates, strictly as data blocks:

  ```text
  TARGET
  company: Example Corp
  country: United Kingdom
  person: Jane Doe

  CANDIDATE RESULTS
  [ {"id":1,"title":"…","url":"https://…","snippet":"…"}, … ]
  ```

- Required JSON output:

  ```json
  { "outcome": "ok | not_found | ambiguous", "keep": [1, 3], "reason": "short explanation" }
  ```

- Handling: unknown `id`s ignored; `keep` de-duplicated and capped at
  `web_research.max_sources`; `outcome=not_found` or empty `keep` → `not_found`;
  `outcome=ambiguous` → `ambiguous`; any failure / unusable JSON → retried up to
  `web_research.filter_attempts` times (a repeat free-tier call usually recovers
  from non-JSON output) and, if still failing, the agent returns `indeterminate`
  and performs no fetch/summarize calls.

### 2a. Best-effort rescue (name-match fallback)

When the filter settles nothing (`not_found`, `ambiguous`, or an empty `keep`)
but at least one candidate still **names the target** (its title or snippet
contains a company word or the person's last/full name — `web_research.rescue_on_name_match`),
the agent fetches those name-matching candidates and runs the **grounded
summarizer** instead of declaring no data. This only ever lets the summarizer
try — it never produces content of its own, so nothing fabricated can pass; the
summary still cites only URLs that were actually fetched.

A rescued profile is flagged `uncertain`:

- `outcome` is forced to `partial`;
- `limitations` is prefixed with: *"Best-effort research: the search returned
  few reliable sources and/or the name matched more than one entity, so some
  details may be confounded with same-name companies or people, or incomplete.
  Verify before relying on these findings."*
- if every matched page fails to fetch, the run is `indeterminate` (never
  fabricated `not_found`/fake data).

## 3. Page fetch boundary

`App\WebResearch\PageFetcher::fetch(string $url): ?array` returning
`['title' => string, 'url' => string, 'text' => string]` or `null`.

- Only `http`/`https`; bounded redirects; `Accept: text/html`.
- `timeout` = `web_research.fetch_timeout`; reject non-2xx and non-HTML/text.
- Body capped at `web_research.fetch_max_bytes`; extracted visible text (scripts,
  styles, comments, tags removed; whitespace collapsed) capped at
  `web_research.source_max_chars`.
- Any failure → `null`; the agent skips that source (does not abort the run).

## 4. Summarize call

One `AiCallingService::complete()` call.

- `system` — **fixed constant**: summarize the target using **only** the supplied
  fetched documents; never invent facts; cite the document(s) each claim comes
  from; put anything unsupported in `limitations`; output strict JSON.
- `user` — target + fetched documents as data blocks (bounded by
  `web_research.summary_max_input_chars`).
- Required JSON output:

  ```json
  { "summary": "string", "sources": [{"title":"string","url":"string"}], "limitations": "string|null" }
  ```

- Handling: `summary` must be a non-empty string or the result is
  `indeterminate`; `sources` are re-mapped against the set actually fetched, so a
  hallucinated citation is dropped. `limitations` is optional and may be `null`.

## 5. Result object

`App\WebResearch\ResearchResult`:

| Field | Type | Notes |
|---|---|---|
| `outcome` | `ResearchOutcome` | `completed` / `partial` / `not_found` / `ambiguous` / `indeterminate` |
| `summary` | string | visitor-safe, source-grounded, or an explicit statement |
| `sources` | `{title,url}[]` | subset of fetched sources |
| `limitations` | ?string | optional; prefixed with the uncertainty note when rescued |
| `uncertain` | bool | `true` when the profile came from the best-effort rescue (few/confounded sources) |
| `audit` | array | candidate-filter snapshot (see 5a) |

### 5a. Candidate filter audit (`audit`)

Every run that gathered at least one candidate produces an audit snapshot so
operators can verify the filter is neither too greedy nor too lax. It is
**persisted** on `classification_results.web_research.audit` and surfaced in the
admin/dashboard detail view, but **not** emitted in any public triage response.

```json
{
  "counts":      {"obtained": 12, "kept": 2, "rejected": 10},
  "kept":        [{"title": "string", "url": "string"}],
  "rejected":    [{"title": "string", "url": "string"}],
  "filter_outcome": "ok|not_found|ambiguous",
  "filter_keep": [1, 5],
  "filter_reason": "string",
  "rescue_used": false
}
```

- `counts` sum the candidate pool after the `max_candidates` cap.
- `kept`/`rejected` are `{title,url}` pairs (title/snippet-level detail only;
  no reply content). When the rescue ran, the rescued candidates count as
  `kept` and `rescue_used` is `true`.
- Runs with zero candidates (`not_found` short-circuit) or indeterminate
  infrastructure failures produce `audit: []`.

`WebResearchService` maps `indeterminate` → `WebResearchVerdict::indeterminate`;
every other outcome → `WebResearchVerdict::accept($findings)` where `$findings`
is the `{outcome, summary, sources, limitations, uncertain}` object.

## Injection and privacy rules (FR-001, FR-009)

- The AI system prompts are fixed constants; the target, candidate results, and
  fetched page content appear **only** in the user role as data blocks.
- Candidate/result/page content can never change the instructions, the output
  schema, or the outcome mapping.
- The provider receives only `company_name` + `country_region` + person name +
  company context; email/phone never appear in any provider, AI, or fetch
  request (asserted by test).
