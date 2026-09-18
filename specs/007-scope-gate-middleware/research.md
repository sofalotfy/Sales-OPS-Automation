# Research: Scope Gate Middleware

**Feature**: [spec.md](spec.md) — Phase 0 output of `/speckit.plan`.

This document resolves the design decisions needed before Phase 1. Format per artifact: Decision, Rationale, Alternatives considered.

## R1. Gate placement and request lifecycle

- **Decision**: New HTTP middleware `App\Http\Middleware\ScopeGateMiddleware` registered on **`POST /inquiry/triage` only**. It runs after global body normalization but before `InquiryController::triage`, and behaves as:
  - Decode the body; if it is not a JSON object → **pass through** (the controller returns the existing `400` unchanged).
  - Extract the canonical message via the existing `MessageExtractor`; if validation fails → **pass through** (the controller returns the existing `422` unchanged). The gate never screens an invalid payload (spec Edge Cases).
  - Otherwise run the scope check and decide (R2).
- **Rationale**: Satisfies FR-001 ("before the full classification flow") and the spec assumption that the gate covers only the public triage submission path, not operator/admin endpoints. The pass-through on parse/validation keeps the existing error contract byte-for-byte (the same behavior-preserving discipline as SC-002 for the accept path). CSRF/body-normalization middleware is already configured not to mutate inquiry payloads (`bootstrap/app.php`), so the gate reads the raw body safely. `MessageExtractor` remains the active extraction path — its stale `@deprecated` tag (leftover from the feature-006 design docs, it is still used by the controller) is cleaned up during implementation.
- **Alternatives considered**: Gate logic inside the controller (rejected — the user explicitly asked for a middleware); gate applied to admin endpoints too (rejected — spec assumption: operators are a different audience); gate before body normalization (rejected — trimming/empty-string conversion would skew validation, exactly why the framework already exempts `inquiry/*` from it).

## R2. Verdict model and fail-open mapping

- **Decision**: Value object `App\ScopeGate\ScopeVerdict` with `outcome: 'accept' | 'decline' | 'indeterminate'`, `reason: string`, and `refusal: ?string` (visitor-facing copy, present only for `decline`). Decision rules, applied in order:
  1. Retrieval fails (connection error, timeout, HTTP non-success) **or** returns `result_count === 0` (no grounding) → **indeterminate**.
  2. AI call fails (missing key, connection error, timeout, HTTP non-success) **or** output is unparseable or missing the expected fields → **indeterminate**.
  3. AI verdict `in_scope: true` → **accept**. `in_scope: false` (requires a non-empty `reason`) → **decline**.
  4. Anything indeterminate → **fail open**: pass the request to the classification flow unchanged (spec Clarifications Q2 / FR-007); the verdict is still recorded (R3).
- **Rationale**: Maps FR-004/FR-007 and the clarification decisions exactly. Because every ungrounded/unparseable case resolves to `indeterminate` before accept/decline is considered, the gate can never fabricate a scope decision — it is fail-open by construction.
- **Alternatives considered**: Fail-closed decline on any doubt (rejected during the clarification session — risks wrongly refusing real in-scope inquiries); adding an "escalate"-style third outcome (rejected — that would need a new response shape, contradicting the refusal-shape clarification).

## R3. Decline persistence and response contract reuse

- **Decision**:
  - **Persistence**: `classification_results` gains three nullable columns — `scope_check_outcome` (`accept`|`decline`|`indeterminate`), `scope_check_reason` (text), `refusal` (text). A `decline` row is written directly by the gate (message, `classification='disqualify'`, `final_score=0.00`, empty `factor_scores`/`dropped_factors`, `reasoning`=scope reasoning, `retrieved_context`=the gate's retrieval, `scope_check_outcome='decline'`, `refusal`=reason). The stakeholder's phrasing — "recorded in the sales inquiry table as the outcome of the value of scope check" — is realized as the dedicated `scope_check_outcome` column (FR-012, SC-007).
  - **Response**: A declined inquiry returns the **same top-level shape** as a triage response (Clarification Q3): `{classification: 'disqualify', score: 0.0, factor_scores: {}, dropped_factors: [], reply: <refusal>, reasoning: <scope reasoning>, context: {inquiry, retrieved_context, scope_check: {outcome: 'decline', reason}}}`. The `context.scope_check.outcome = "decline"` is the marker that the inquiry was refused (FR-006). No new shape, no new status code.
- **Rationale**: The widget renders a refusal via the existing `reply` field with zero changes; reviewers find declines through the `scope_check_outcome` column (SC-006/SC-007); accepted/indeterminate verdicts ride the same request into the scoring flow's existing insert (R6). Reusing the shape also keeps the contract version bump additive rather than breaking.
- **Alternatives considered**: A separate refusal endpoint or body (rejected in Clarification Q3); emitting a booking-style reply for declines (rejected — no handler is involved and the reason must always be visitor-facing); storing declines in a new table (rejected — the spec's "sales inquiry record" is the existing `classification_results` log, which review already reads).

## R4. Scope reasoning prompt + injection safety

- **Decision**: `App\ScopeGate\ScopeCheckService` owns a fixed system prompt (language constant): the deployment `company_scope` statement is the only injected string, plus the binary scope rule and a strict JSON output contract `{"in_scope": boolean, "reason": string}`. The inquiry message and retrieved documents are placed strictly in the **user** role as `[USER INQUIRY]` / `[RETRIEVED DOCUMENTS]` data blocks — never in the system role. The check calls the existing `AiCallingService::complete()` (JSON mode with the established 422→plain fallback). A `decline` requires a non-empty `reason`, which becomes both the stored reasoning and the visitor refusal copy.
- **Rationale**: Reuses the isolated AI caller (feature 006 R5) so there is one place that knows the provider mechanics. The fixed prompt + data-block separation gives the same injection-safety guarantee `PromptBuilder` enforced (FR-003/FR-009/FR-010 and the spec's edge case: visitor/retrieved content is data, never instructions). JSON mode keeps parsing deterministic.
- **Alternatives considered**: Reusing the deprecated `PromptBuilder`/`AiCallingService::triage()` (rejected — that endpoint is the obsolete three-way disposition flow; the gate needs a binary contract); free-text AI output (rejected — unparseable cases would spike the indeterminate rate and make refusal copy nondeterministic).

## R5. Retrieval for the gate

- **Decision**: Reuse `RagApiClient::query(message, top_k)` — same endpoint, same service-token flow and single 401-retry. Any failure (`failed()`, connection error, timeout) → **indeterminate** (fail open). `result_count === 0` → **indeterminate**. The gate's retrieval is deliberately independent of the classification flow's retrieval; for v1 the same documents may be fetched twice (spec Assumption — sharing is explicitly out of scope).
- **Rationale**: Zero new retrieval code (FR-002); `RagApiClient::query` already returns the raw `Response` so the gate treats non-success exactly like feature 006's `InquiryTriageService::rags()` does — i.e., no-grounding, which the verdict rules route to indeterminate (R2). Consistency with the existing degradation path avoids a second failure dialect.
- **Alternatives considered**: Sharing the gate's retrieved context with the classification flow by attaching it to the request (deferred — couples the middleware to `InquiryTriageService`'s context assembly; revisit only if RAG cost becomes material).

## R6. Verdict hand-off and configuration

- **Decision**: The middleware stashes the computed verdict on the request (`$request->attributes->set('scope_check_verdict', ScopeVerdict)`). For accept/indeterminate it then calls `$next($request)`; `InquiryController` passes the verdict to `InquiryTriageService::triage($inquiry, ?ScopeVerdict)`, which (a) includes `scope_check: {outcome, reason}` in the response `context` and (b) writes `scope_check_outcome`/`scope_check_reason` (and `refusal=null`) on the classification row. For decline the middleware itself inserts the record (R3) and returns the refusal response — the scoring engine never runs (SC-001).
- **Configuration**: New `config/scope_gate.php`: `enabled` (`SCOPE_GATE_ENABLED`, default `true`) as a kill switch, `rag_top_k` (`RAG_TOP_K`, default 5, same env as today). AI provider/model/key keep coming from `services.zai.*` (FR-011 — no new secrets).
- **Rationale**: The verdict being a request attribute keeps the middleware a true pass-through on the accept/fail-open paths while guaranteeing FR-012 (every screened inquiry's outcome lands on the sales inquiry record). A kill switch makes an always-on AI+RAG pre-screen safe to disable in an incident without a deploy.
- **Alternatives considered**: Reading the verdict via the framework `request()` helper inside the service (rejected — implicit coupling; injection via the controller keeps it explicit); no kill switch (rejected — forces a code change to bypass the gate); a dedicated env secret for the gate (rejected — same provider, same key).

## R7. Testing strategy

- **Decision**: PHPUnit on the existing inquiry-handler suite (SQLite `:memory:` + `RefreshDatabase`, `Http::fake()` + `UpstreamStubs`).
  - **Feature** `ScopeGateTest`: accept path (200 triage response, `context.scope_check.outcome=accept`, stored `scope_check_outcome=accept`); decline path (200 refusal shape, `classification=disqualify`, `reply`=reason, `context.scope_check.outcome=decline`, stored record `scope_check_outcome=decline`+`refusal`+`scope_check_reason`, engine not engaged); indeterminate paths (RAG 5xx, RAG timeout, RAG returns zero results, AI unreachable, AI credentials missing, AI returns garbage) → still a 200 triage response with `scope_check_outcome=indeterminate`; invalid body → 400 and validation error → 422 with no gate screening.
  - **Unit** `ScopeCheckServiceTest` (retrieval→indeterminate, AI→indeterminate, parse branches, prompt separation: message/docs appear only in the user role), `ScopeVerdictTest`.
- **Rationale**: Mirrors feature 006's proven approach (throwaway store, faked HTTP, no live Postgres in tests) and frames each spec acceptance scenario and success criterion as an assertion.
- **Alternatives considered**: Live-stack integration test only (used in quickstart as manual validation instead; the Laravel service has no pytest/contract-test harness); pitting the gate against the real Z.AI API in tests (rejected — nondeterministic and key-dependent).

## R8. Constitution re-check (post-design)

- **Decision**: Re-checked after Phase-1 design, no violations surfaced. I. Independently deployable services / HTTP-only — the feature is purely additive inside the existing inquiry-handler (middleware + one migration); every outbound call is an existing HTTP consumer (auth-service token, work-scope-rag `/query`, Z.AI HTTPS); no shared imports, no cross-service DB access. II. API-first / FastAPI default — no new service; the Laravel deviation was already recorded and carried by features 004/005/006. III. Human-in-the-loop — indeterminate fails open, declines are persisted for human review, nothing is dropped or auto-resolved (FR-007/FR-008). IV. Data model source of truth — all scope-check state lives in the scoped `inquiry_handler` store (`classification_results`), readable via the existing review API. V. Simplest version — no new service, no new table, three additive columns, reuse of `RagApiClient`, `AiCallingService`, `MessageExtractor`.
- **Rationale**: The only "new" surface is one middleware class plus a value object/service; everything else is an additive change to code and schema the stack already owns.
- **Alternatives considered**: n/a.