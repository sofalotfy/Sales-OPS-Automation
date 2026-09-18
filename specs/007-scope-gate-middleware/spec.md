# Feature Specification: Scope Gate Middleware

**Feature Directory**: `007-scope-gate-middleware`

**Feature Branch**: `007-scope-gate-middleware`

**Created**: 2026-09-14

**Status**: Draft

**Input**: User description: "I want to add a middleware with the scope metric we once had to retrieve documents from the RAG system and send the documents with the sales inquiry to the AI model to check if it is in scope, then it will take a decision: (1) accept → the middleware just passes the request through; (2) decline → it displays the reason for refusing."

## Clarifications

### Session 2026-09-14

- Q: How should declined inquiries be recorded for human review? → A: Every declined inquiry is recorded for later human review — the message, the refusal, and the reason — so no inquiry vanishes from the sales team's view.
- Q: What should the gate do when it cannot determine scope? → A: Fail open — the inquiry is passed through to the full classification flow unchanged, which already degrades gracefully and keeps every inquiry visible to a human. The gate never fabricates a scope decision.
- Q: What response shape should a declined inquiry's refusal use? → A: Reuse the existing classification response contract — the visitor-facing message carries the reason, plus a marker indicating the inquiry was declined; no new response shape is introduced and the widget renders it unchanged.
- Q: How should the gate's scope-check decisions be recorded for operational review? → A: The scope-check outcome value (accept / decline / indeterminate) is stored on the sales inquiry record itself — every inquiry the gate screens carries its scope-check verdict in the persisted inquiry record; no separate gate log.
- Q: What latency budget should the gate's scope check be held to? → A: No fixed budget in the spec — SC-004's "within a few seconds" target is kept open-ended and the exact budget is validated via load testing during implementation.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - In-Scope Inquiries Pass Straight Through to Classification (Priority: P1)

A visitor submits a sales inquiry about something the company actually sells. Before the full classification runs, the scope gate retrieves the company's knowledge documents, judges the inquiry against them, and "accepts" it — the request then continues to the same classification flow exactly as it does today. The visitor sees the normal classification outcome (score, classification, reply, booking link when qualified); the gate's presence is invisible except for the small extra step it performed.

**Why this priority**: This is the core contract the user asked for — the middleware "just passes" when in scope. It must add the gate without breaking the existing in-scope experience.

**Independent Test**: Submit an in-scope sales inquiry and confirm the full classification response is returned unchanged (same fields, same reply/booking behavior as before the gate was added).

**Acceptance Scenarios**:

1. **Given** an inquiry that is clearly within the company's scope, **When** it is submitted, **Then** the gate accepts it and the request proceeds to the full classification flow unchanged.
2. **Given** an in-scope inquiry, **When** the full classification runs after the gate, **Then** the visitor receives the same response shape they receive today (classification, score, per-factor scores, reply, context).
3. **Given** an in-scope inquiry that would qualify for a booking link, **When** classification completes, **Then** the visitor still receives the booking link exactly as before.

---

### User Story 2 - Out-of-Scope Inquiries Are Stopped With the Reason (Priority: P1)

When the visitor's question is not something the company handles (unrelated request, wrong audience, or noise), the scope gate "declines" it before full classification runs. The visitor does not continue through the classification flow; instead they immediately receive a refusal that explains the reason — the decision is grounded in the retrieved documents, not a generic corporate message.

**Why this priority**: This is the second half of the user's core contract — declined inquiries must "display the reason for refusing," so the visitor understands why they were turned away.

**Independent Test**: Submit an out-of-scope inquiry and confirm the request does NOT reach the classification flow and that the visitor receives a refusal whose message explains the reason, delivered in the same response shape the endpoint already uses.

**Acceptance Scenarios**:

1. **Given** an inquiry that is out of the company's scope, **When** it is submitted, **Then** the gate declines it before classification and the visitor receives a refusal response in the existing response shape.
2. **Given** a declined inquiry, **When** the refusal is produced, **Then** the refusal's message explains the specific reason for refusing, grounded in what the company actually does, and carries a marker indicating the inquiry was declined.
3. **Given** a declined inquiry, **When** the visitor continues submitting unrelated messages, **Then** each is screened independently and refused consistently.

---

### User Story 3 - The Gate Handles Uncertain Scope Checks Gracefully (Priority: P1)

The gate depends on two upstream things to make its decision: retrieving relevant knowledge documents, and the AI's scope judgment. When either cannot be obtained — the retrieval store or the AI is temporarily unreachable, or no relevant documents come back for the inquiry — the gate cannot honestly say "in scope" or "out of scope". In that situation the gate must not guess or fabricate a scope decision, and the inquiry must still end up somewhere safe.

The agreed behavior (Clarifications Q2 — fail open): when the gate cannot determine scope, the inquiry is passed through to the full classification flow unchanged — which already degrades gracefully and keeps every inquiry visible to a human. The gate never fabricates a scope decision and never drops the inquiry.

**Why this priority**: Constitution principle III (human-in-the-loop) — a degraded check must never silently drop or auto-resolve an inquiry, and a fabricated scope decision would be worse than no gate.

**Independent Test**: Force the retrieval store or the AI check to fail and confirm the inquiry still reaches the full classification flow unchanged — never fabricated as accept or decline, never dropped.

**Acceptance Scenarios**:

1. **Given** the retrieval store or AI check is temporarily unavailable, **When** an inquiry is submitted, **Then** the gate returns the defined indeterminate outcome — it never fabricates an accept or decline decision.
2. **Given** an inquiry with no relevant retrieved documents, **When** it is submitted, **Then** the gate treats it as indeterminate rather than guessing.
3. **Given** an indeterminate outcome, **When** handling completes, **Then** the inquiry is passed through to the full classification flow unchanged — nothing is silently dropped.

---

### User Story 4 - Declined Inquiries Remain Accountable (Priority: P2)

Out-of-scope inquiries are still real visitors reaching out. The system today keeps every inquiry visible to a human reviewer regardless of how it is handled. A declined inquiry should not silently vanish — it must be possible to know, later, that it was refused, when, and why.

The agreed behavior (Clarifications Q1): every declined inquiry is recorded for later human review, exactly like classified inquiries — the message, the refusal, and the reason — so out-of-scope requests never silently vanish from the sales team's view, while the visitor still gets the refusal immediately.

**Why this priority**: Framing question for the recording decision (see Clarifications) — governance/observability matters, but the refusal already works without any record.

**Independent Test**: Submit an out-of-scope inquiry and confirm a reviewable record exists afterward containing the message, the refusal, the reason, and the scope-check outcome (`decline`) — alongside the visitor receiving the refusal.

**Acceptance Scenarios**:

1. **Given** a declined inquiry, **When** it is refused, **Then** a record is stored containing the message, the refusal, the reason, and the scope-check outcome (`decline`), and the visitor still receives the refusal immediately.
2. **Given** a recorded declined inquiry, **When** a reviewer looks it up later, **Then** they can see the message, the refusal, the reason, and the scope-check outcome.

---

### Edge Cases

- A blank, whitespace-only, or oversized message is rejected by the existing validation before the gate runs; the gate never screens an invalid payload.
- The retrieval store is temporarily unavailable — the gate cannot ground a scope decision; handled per the indeterminate behavior, not guessed.
- The AI model is unreachable, times out, or the credentials are misconfigured — the gate cannot judge scope; handled per the indeterminate behavior, not guessed.
- The AI returns an answer the gate cannot interpret — treated as indeterminate, never coerced into accept or decline.
- No relevant documents exist in the knowledge store for the inquiry — the gate cannot confirm or deny scope from grounding; treated as indeterminate.
- The visitor's message or a retrieved document tries to look like a system instruction (prompt-injection style content) — it is strictly data; it cannot change the scope rules or the refusal copy.
- A visitor submits rapidly in succession — each inquiry is screened and handled independently, with the same reasonable rate protection the public endpoint already has.
- Malformed non-object JSON payloads are rejected as today (400) before the gate; the gate only sees valid inquiry payloads.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Every submitted sales inquiry SHALL be screened by a scope gate before the full classification flow runs.
- **FR-002**: To make its scope decision, the gate SHALL retrieve the relevant knowledge documents for the inquiry.
- **FR-003**: The gate SHALL present the inquiry and the retrieved documents to the AI as strictly separate data — neither may alter the scope rules, the system prompt, or the decision logic.
- **FR-004**: The gate SHALL produce exactly one of two decisions per inquiry: **accept** (in scope) or **decline** (out of scope), except in the indeterminate cases covered by FR-007.
- **FR-005**: On an **accept** decision, the gate SHALL pass the request through unchanged so the classification flow completes exactly as it does today.
- **FR-006**: On a **decline** decision, the gate SHALL stop the request before classification and return a refusal to the visitor that includes the specific reason for refusing, grounded in what the company does. The refusal SHALL reuse the existing classification response contract — the visitor-facing message carries the reason plus a marker indicating the inquiry was declined — and SHALL NOT introduce a new response shape.
- **FR-007**: When the gate cannot determine scope (retrieval or AI unavailable, unparseable AI output, or no relevant documents), it SHALL fail open: the inquiry passes through to the full classification flow unchanged, which keeps every inquiry visible to a human. The gate SHALL NOT fabricate an accept or decline decision and SHALL NOT silently drop the inquiry.
- **FR-008**: Every declined inquiry SHALL be recorded for later human review, containing the message, the refusal, and the reason, applied consistently to every declined inquiry — and the visitor SHALL still receive the refusal immediately.
- **FR-009**: The gate SHALL derive its scope judgment from the single configured scope statement for the deployment, and never from request content.
- **FR-010**: The gate SHALL make no changes to how in-scope inquiries are validated, classified, or replied to — the accept path is behavior-preserving.
- **FR-011**: The AI provider, model, and credentials used by the gate SHALL come from configuration, with credentials never committed to source control or logged.
- **FR-012**: For every inquiry the gate screens, the scope-check outcome value (**accept**, **decline**, or **indeterminate**) SHALL be recorded on the persisted sales inquiry record itself — accepted and indeterminate outcomes on the record the classification flow produces, and declined outcomes on the refusal record — so every gate decision is auditable without a separate gate log.

### Key Entities

- **Inquiry**: The canonical message plus optional contact fields submitted by the visitor — the same entity the classification flow already processes.
- **Retrieved Context**: The knowledge documents relevant to the inquiry, used as the grounding for the scope decision.
- **Scope Verdict**: The gate's decision — **accept**, **decline**, or **indeterminate** — together with the reasoning (and, for a decline, the visitor-facing refusal reason) and the retrieved grounding that produced it. The outcome value is recorded on the persisted sales inquiry record (FR-012).
- **Refusal**: The visitor-facing decline response containing the reason for refusing, delivered in the same response contract the classification endpoint already uses (with a marker indicating the inquiry was declined).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of out-of-scope inquiries are stopped by the gate and receive a refusal that includes the reason in the existing response shape; none proceed to full classification.
- **SC-002**: 100% of in-scope inquiries pass through the gate and receive exactly the same classification response they would have received without the gate.
- **SC-003**: 100% of indeterminate gate outcomes pass through to the full classification flow — the gate never accepts or declines on an uncertain check, and no inquiry is silently dropped.
- **SC-004**: The gate adds only the time of its own scope check to the total response time; a scope decision is returned to the visitor within a few seconds of submission.
- **SC-005**: 100% of refusal messages give the visitor a clear, non-technical reason rather than a generic "please contact us" placeholder.
- **SC-006**: 100% of declined inquiries are findable in the review log with the message, the refusal, the reason, and the scope-check outcome.
- **SC-007**: 100% of inquiries the gate screens (accepted, declined, and indeterminate) have their scope-check outcome recorded on the persisted sales inquiry record.

## Assumptions

- The scope gate realizes the "scope metric" the project once designed — the scope-relevance assessment that was planned as a classification factor — but as a standalone pre-screen in front of the full classification engine, per the user's explicit request for middleware behavior ("passes" vs "declines").
- The gate runs on the public inquiry submission path only; it does not apply to operator/admin endpoints (weight management, classification review), which are a different audience.
- The gate reuses the existing retrieval system and the AI provider already in use; no new external systems are introduced.
- The gate's retrieval is independent of the classification flow's own retrieval. For v1 the same documents may be fetched twice (once by the gate, once by classification); optimizing to share one retrieval is out of scope.
- A decline is regarded as a determinate, confident outcome, so the reason can be given freely; the indeterminate (cannot-tell) cases are the ones covered by the specific clarification.
- The refusal reason is generated from the retrieved knowledge, not from a fixed template; exact copy phrasing will be confirmed with the sales team later, but a reason is always present.
- Contact fields (name/email) are not part of the scope judgment — only the message is screened, matching the existing behavior.
- Rate limiting and abuse protection on the public endpoint are already in place and are not changed by this feature.
- The exact latency budget for the gate is deliberately not fixed in the spec: SC-004's "within a few seconds" target is validated via load testing during implementation (see Clarifications).

## Deviations from Constitution (recorded)

- No deviation. The feature is fully additive within the existing inquiry-handler service (constitution Gates I and IV are unaffected — no new store access, HTTP-only consumption stays as today). Principles III (human-in-the-loop — indeterminate cases are never silently dropped) and V (smallest version that satisfies the spec) are honored.