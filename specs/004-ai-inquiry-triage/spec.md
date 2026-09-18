# Feature Specification: AI Sales Inquiry Triage

**Feature Directory**: `004-ai-inquiry-triage`

**Feature Branch**: `004-ai-inquiry-triage`

**Created**: 2026-09-12

**Status**: Draft

**Input**: User description: "Create a new laravel service that exposes a frontend widget (chat-like) where a user writes a question/sales inquiry. The question goes to the RAG vector store to retrieve relevant documents/chunks. The question plus the retrieved documents and chunks are handed to an AI system (Groq hosting an open-source model, API key via an environment variable) with a clearly separated system prompt injection point. The AI chooses one of 3 services, implemented as separate PHP classes in the same folder: (1) decline when the inquiry is out of company scope or not a sales inquiry, (2) escalate to a human, (3) send a booking link. Also a message extraction service that pulls the message out of the incoming payload — future external apps may send a predefined schema, but for now it is just a message."

## Clarifications

### Session 2026-09-12

- Q: When an inquiry escalates, where should the escalation record land so a human can act on it? → A: v1 escalate does nothing beyond returning a response to the inquirer indicating the decision was taken; no persistence, webhook, email, or CRM hand-off. The real escalation mechanism is deferred (unknown/unset requirements).
- Q: What contact information should the widget collect alongside the inquiry message? → A: Optional name and email fields alongside the message.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Visitor Submits a Sales Inquiry (Priority: P1)

A visitor on the widget types a question about the company's products or services. The system finds relevant knowledge from the RAG document store, looks up relevant documents, and responds within the widget with the outcome of triage — a decline, an escalation, or a booking-link invitation — based on what was asked, with the reasoning grounded in retrieved knowledge.

**Why this priority**: This is the core value — taking a raw inquiry to a confident disposition without a human reading every message.

**Independent Test**: Submit a sales inquiry through the widget and confirm a disposition is returned (one of the three outcomes) with content taken from the retrieved knowledge; the answer is grounded in the RAG store.

**Acceptance Scenarios**:

1. **Given** a visitor on the widget, **When** they type a valid sales inquiry and submit, **Then** the system retrieves relevant document chunks and returns a disposition within a reasonable time (seconds).
2. **Given** an inquiry requiring a booking link, **When** a disposition of "booking" is produced, **Then** the visitor receives a working booking link.
3. **Given** an ambiguous inquiry, **When** the AI cannot confidently classify it, **Then** the inquiry is escalated rather than guessed or dropped.
4. **Given** an out-of-scope or non-sales message, **When** a disposition of "decline" is produced, **Then** the visitor receives a polite decline explaining the company does not handle such requests.
5. **Given** a visitor may optionally provide name and email, **When** they fill the contact fields, **Then** the fields are accepted with the inquiry (valid email required if provided); the same flow works without them.

---

### User Story 2 - Escalate Unclear or High-Value Inquiries to a Human (Priority: P1)

When the triage system cannot confidently classify an inquiry, or the inquiry clearly needs a person (complex, sensitive, high-value, or simply ambiguous), the system routes it to the escalate disposition and informs the inquirer that the decision was taken, rather than silently resolving or dropping it. For v1 the escalate action is only the decision + its response to the inquirer; the actual hand-off to a human is deferred (see Clarifications).

**Why this priority**: Constitution principle III (Human-in-the-Loop, non-negotiable) — automation augments sales judgment, it never replaces it for judgment calls; v1 keeps the disposition honest even though the delivery mechanism is deferred.

**Independent Test**: Submit an ambiguous or clearly human-necessitated inquiry and confirm the system returns an "escalate" disposition with a response indicating the decision.

**Acceptance Scenarios**:

1. **Given** an inquiry the AI cannot confidently route, **When** triage completes, **Then** the outcome is "escalate" and the inquirer receives a response indicating the decision was taken (v1 performs no further action).
2. **Given** an inquiry that is in-scope sales but complex (pricing, customization, legal), **When** triage completes, **Then** it escalates rather than auto-responding with a possibly wrong answer. It remains in scope to record/handle the escalation when the mechanism is defined.

---

### User Story 3 - Send a Booking Link for Qualified Inquiries (Priority: P2)

When the AI determines the visitor is a viable sales prospect ready to meet, the system invites them to book a meeting by providing the configured booking link.

**Why this priority**: Direct business value (meetings booked) but depends on triage core being solid first.

**Independent Test**: Submit a qualified, meeting-ready inquiry and confirm a booking link is presented to the visitor.

**Acceptance Scenarios**:

1. **Given** an inquiry classified as meeting-ready, **When** triage completes, **Then** the visitor sees the configured booking link.
2. **Given** no booking link is configured, **When** triage would choose "booking", **Then** the system escalates instead of returning a broken or missing link.

---

### User Story 4 - Decline Out-of-Scope Inquiries Gracefully (Priority: P2)

When the visitor's question is not about the company's offerings (unrelated request, wrong audience, spam-like noise), the system politely declines, keeping human effort focused on real opportunities.

**Why this priority**: Keeps the sales team and RAG budget focused; secondary to the core triage flow.

**Independent Test**: Submit unrelated messages and confirm every one receives a decline response and is not escalated or booked.

**Acceptance Scenarios**:

1. **Given** a clearly off-topic or non-sales message, **When** triage completes, **Then** the visitor receives a courteous decline explaining scope.
2. **Given** a decline, **When** the visitor continues asking, **Then** the system handles subsequent unrelated messages consistently (each triaged independently).

---

### User Story 5 - Extract the Message From a Structured Payload (Priority: P3)

The backend accepts inbound inquiries from an input payload. An extraction step normalizes whatever envelope the sender used (today: plain text from the widget; later: a predefined schema from external applications) into a single canonical message before triage runs.

**Why this priority**: Future-proofing for external integrations; not needed for the widget to work today.

**Independent Test**: Submit a payload whose message is nested inside structural fields and confirm the message is extracted and triaged as if typed directly.

**Acceptance Scenarios**:

1. **Given** a plain-text message from the widget, **When** it is received, **Then** it is extracted unchanged into the canonical message.
2. **Given** a structured payload resembling a future external-app schema, **When** it is received, **Then** the extraction step produces the same canonical message the plain-text path produces.

---

### Edge Cases

- Empty or whitespace-only input is rejected with a clear prompt; no RAG or AI call is made.
- Input exceeds a reasonable length limit; the submitter is asked to shorten it rather than the system failing.
- A malformed optional email (for example `user@` or no domain) is rejected with a clear message; name/email remain optional so the visitor can submit the message alone.
- The RAG store is temporarily unavailable; the user receives a clear "try again shortly" response, no wrong disposition is fabricated.
- The AI provider is unreachable, times out, or the API key is missing/invalid; the system surfaces the error and escalates the inquiry instead of guessing.
- The AI returns an outcome form the system cannot map to one of the three dispositions; the inquiry is escalated by default (never dropped, never guessed).
- Escalations in v1 produce only the decision + response to the inquirer; no record is persisted or forwarded until the escalation mechanism is defined.
- AI output suggests injection or prompt-override content in the visitor's message; the message is treated strictly as data, and the system prompt boundary is respected (never allow the retrieved content or visitor text to override triage rules).
- A visitor submits rapidly in succession; each inquiry is triaged independently and there is reasonable rate protection so the service is not abused.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST expose a widget where a visitor can type a sales inquiry/version of a question and submit it, with optional name and email contact fields alongside the message.
- **FR-002**: System MUST validate the submitted message (non-empty, within length limits) and reject invalid submissions with a clear prompt. If provided, the email MUST be a valid email format; name and email are otherwise optional.
- **FR-003**: System MUST send the submitted question to the RAG vector store and retrieve the most relevant documents/chunks.
- **FR-004**: System MUST pass the retrieved chunks (with their source documents) together with the user question to the AI, and MUST keep the retrieval and user content strictly separate from the fixed system prompt.
- **FR-005**: System MUST expose exactly three dispositions: decline, escalate, and booking. Every inquiry results in one of these three — never a silent drop.
- **FR-006**: System MUST decline inquiries that are out of the company's scope or not sales inquiries, with a courteous explanation.
- **FR-007**: System MUST route ambiguous, complex, or otherwise human-necessary inquiries to the "escalate" disposition. For v1, escalate means returning a response to the inquirer indicating the decision was taken; further action (persisting an escalation record, notification, or CRM hand-off) is deferred.
- **FR-008**: System MUST send a booking link (from a configurable source) to the visitor for inquiries classified as booking-ready.
- **FR-009**: System MUST escalate whenever a booking disposition cannot be fulfilled (no booking link configured, or booking resource unavailable) instead of returning a broken link.
- **FR-010**: System MUST escalate by default whenever the AI output is unparseable, the AI is unreachable, times out, or credentials are misconfigured.
- **FR-011**: System MUST allow configuration of the AI provider, model, and API key through environment variables/configuration rather than hardcoding (the API key MUST never be committed or logged).
- **FR-012**: System MUST treat visitor message content and retrieved document content strictly as data — neither may override the system prompt, triage rules, or the AI calling logic.
- **FR-013**: System MUST normalize inbound payloads into a canonical message via an extraction step, supporting today's plain-text widget messages and leaving a clear path for structured schemas from external apps.
- **FR-014**: System MUST respond with a clear, user-friendly message when the RAG store or AI is temporarily unavailable, and MUST NOT return a fabricated disposition in those cases.
- **FR-015**: System MUST provide a `/health` endpoint consistent with the rest of the stack.
- **FR-016**: System MUST be delivered as its own independently deployable service in the project's Docker Compose stack, following the constitution's service-boundary rules (HTTP-only communication).

### Key Entities *(feature involves data)*

- **Inquiry**: The canonical message extracted from an inbound payload, plus optional contact fields (`name`, `email` submitted by the visitor) when present. Today it is the visitor's widget text, later it may come from an external app's schema.
- **Retrieved Context**: The set of document chunks (and their source documents) the RAG store returns for the inquiry, used as grounding for the disposition.
- **Disposition**: The triage outcome — decline, escalate, or booking — plus the reasoning/context that produced it.
- **Booking Link**: A configurable meeting link used for the booking disposition.
- **Escalation Record**: The escalated inquiry plus its response. For v1 this is a transitory in-memory object returned to the inquirer; it is NOT persisted or handed to a human until the escalation mechanism is defined (see Clarifications).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of valid inquiries receive exactly one of the three dispositions (decline, escalate, booking) — no silent drops, no fabricated answers.
- **SC-002**: 100% of inquiries that are ambiguous or that trigger a system failure (AI/RAG unavailable, unparseable AI output) receive an "escalate" disposition and its decision response rather than being guessed or dropped.
- **SC-003**: A qualified, booking-ready inquiry reaches the visitor's booking link in under 60 seconds of the submission confirmation.
- **SC-004**: 100% of out-of-scope or non-sales messages receive a decline without engaging a human or a booking link.
- **SC-005**: 100% of booking dispositions only reference booking links that are actually configured and usable.
- **SC-006**: The API key, model, and provider are fully configurable via environment variables with no secrets in source control (verified by repository scan and by rotating the key and restarting without recompilation).

## Assumptions

- The widget is delivered as its own new Laravel-based service in the Docker Compose stack (matching the existing dashboard service pattern) — an intentional, user-specified deviation from the constitution's "FastAPI by default" for UIs, since the user explicitly asked for a Laravel service.
- The AI provider is Groq hosting an open-source conversational model, with the API key supplied via an environment variable (`*_API_KEY` convention, e.g. `GROQ_API_KEY`) and the model selectable via configuration. This is a user-specified choice and supersedes the constitution's Anthropic default for this feature.
- The three dispositions are implemented as three separate, self-contained handler classes in the same folder, plus a separate AI-calling service and a message-extraction service, per the user's explicit design directive.
- RAG retrieval reuses the existing `work-scope-rag` service and its vector store; no new document pipeline is built in this feature.
- The booking link is a single configurable meeting link for v1 (a single configuration value or entry); per-inquiry dynamic booking is out of scope.
- Escalation for v1 is the decision plus a response to the inquirer only — no persistence, notification, or CRM hand-off until the escalation mechanism is defined (per Clarifications).
- The widget's public page is the primary consumer; external applications calling the backend with structured schemas are out of scope for v1 but the extraction step must not paint this feature into a corner.
- Authentication/authorization, if any audience gets a protected backend, follows the project's existing approach (auth-service token flow) — the widget itself is treated as a visitor-facing entry point.
- Rate limiting and basic abuse protection are in scope as reasonable guardrails (per Edge Cases), not a full security suite.

## Deviations from Constitution (recorded)

- **Technology Constraint**: The constitution defaults backend/UI services to FastAPI/Streamlit; this feature is a Laravel service per explicit user request (parallel to the dashboard, which already deviates to Laravel). Consider amending the constitution's technology constraint to note Laravel for UI services.
- **LLM Provider Constraint**: The constitution pins Anthropic as the LLM provider behind a thin wrapper; this feature uses Groq/open-source per explicit user request. The thin-wrapper rule still applies so the provider can be swapped.
- Constitution principles III (human-in-the-loop), V (simplicity/provisional scope), and the service-boundary rules are honored in full.