# Feature Specification: Rename Inquiry Widget to Inquiry Handler

**Feature Directory**: `005-rename-inquiry-handler`

**Feature Branch**: `005-rename-inquiry-handler`

**Created**: 2026-09-13

**Status**: Draft

**Input**: User description: "i want to do some changes in the inquiry widget laravel application first change the name to inquiry hanlder since it woll mostly be used via api no via widget it's only there for testing"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - External Systems Use the Inquiry Handler API (Priority: P1)

The service that triages sales inquiries is now primarily a programmatic API. An external system sends an inquiry to the triage endpoint and receives a disposition (decline, escalate, or booking) without ever opening a web page. The service is identified everywhere by its new name — Inquiry Handler — so API consumers and operational tooling refer to it consistently.

**Why this priority**: This is the stated purpose of the change — the service exists for API consumers; the new name must reflect that reality.

**Independent Test**: Send an inquiry to the triage API and confirm the exact same request and response contract used before the rename still works, and that the service is announced/identifiable as Inquiry Handler.

**Acceptance Scenarios**:

1. **Given** the renamed service, **When** an inquiry is submitted to the triage API using the established request shape, **Then** the same response shape and status codes as before the rename are returned.
2. **Given** operational or integration documentation, **When** it is read by an API consumer, **Then** the service is named "Inquiry Handler" with no misleading "widget" framing.
3. **Given** the previous name was used somewhere user- or operator-facing, **When** that reference is checked after the change, **Then** it uses the new name.

---

### User Story 2 - Team Refers to the Service by Its Correct Name (Priority: P1)

Developers, testers, and anyone working with the service call it the "Inquiry Handler". The rename is consistent across every place the service's identity appears — interface labels, documentation, configuration, and deployment/service naming — so there is a single, unambiguous name.

**Why this priority**: A half-renamed system creates confusion and stale references; consistency is the core value of this feature.

**Independent Test**: Search the service's user- and operator-facing materials for the old name and confirm nothing user-facing still says "Inquiry Widget".

**Acceptance Scenarios**:

1. **Given** the service after the rename, **When** its identity is checked in user/operator-facing materials, **Then** the name "Inquiry Handler" is used consistently.
2. **Given** the same error or success flow as before, **When** it runs, **Then** behavior is identical to the pre-rename behavior.

---

### User Story 3 - Testers Manually Exercise the Flow on a Test Page (Priority: P2)

The service keeps a simple web page so a developer or tester can manually feed an inquiry and watch the triage result without writing a client. The page is clearly marked as a test console for the Inquiry Handler — it is a verification aid, not the product surface.

**Why this priority**: The page supports testing but is explicitly secondary; the product surface is the API.

**Independent Test**: Open the test page, submit a sample inquiry, and confirm one of the three dispositions renders, with the page visibly labeled as the Inquiry Handler test console.

**Acceptance Scenarios**:

1. **Given** a visitor on the test page, **When** they submit a valid inquiry, **Then** one of the three dispositions is shown alongside the reply.
2. **Given** anyone reading the page, **When** they look at its title/heading, **Then** it is clear the page is the Inquiry Handler's test console, not a customer-facing widget.

---

### User Story 4 - Triage Behavior Is Unchanged by the Rename (Priority: P3)

The rename is purely nominal. Every business rule the service already implements — declining out-of-scope inquiries, escalating ambiguous or failed cases, sending a booking link when qualified — works exactly as before. Nothing about triage logic, dispositions, or failure handling changes.

**Why this priority**: Regression protection; the change must not accidentally alter behavior.

**Independent Test**: Run the existing automated test suite in full; every pre-existing behavioral scenario still passes.

**Acceptance Scenarios**:

1. **Given** the pre-existing triage behavior, **When** the service runs after the rename, **Then** disposition rules, failure handling, and the health endpoint behave identically.

---

### Edge Cases

- A stale reference to the old name buried in low-visibility places (internal comments, cache artifacts) does not affect users; user- and operator-facing references are the bar for completion.
- Renaming the deployment/service identity must not break its own health checks or its reachability for API consumers.
- The test page must remain fully functional after relabeling — its form must still reach the triage API.
- If any external consumer has cached the old service address, they must be re-pointed; no in-repository consumer addresses the service by name, so the rename is self-contained.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The service SHALL bear the name "Inquiry Handler" in every user- and operator-facing place its name appears (interface labels, documentation, configuration naming, service metadata).
- **FR-002**: The primary triage API SHALL remain available at the same endpoint with the same request and response shapes and the same status codes as before the rename.
- **FR-003**: The web page SHALL remain available for manual testing and SHALL be visibly labeled as the Inquiry Handler's test console, clearly distinct from a customer-facing product surface.
- **FR-004**: The service's deployment identity (service name, container name, and related infrastructure naming) SHALL advance to the new name wherever it is currently defined.
- **FR-005**: All three dispositions (decline, escalate, booking) and their existing rules, including escalate-by-default on ambiguity or failure, SHALL be preserved unchanged.
- **FR-006**: The health endpoint SHALL remain available and behave identically, and stack health ordering SHALL remain intact after the rename.
- **FR-007**: The service's documentation SHALL describe the API-first usage as the primary interface and the web page as a testing aid, using the new naming throughout.
- **FR-008**: The existing automated test suite SHALL pass in full after the rename; assertions that reference the old name or page labeling SHALL be updated to the new naming, with no reduction in behavioral coverage.

### Key Entities

- **Inquiry Handler**: The renamed service. Formerly announced as "Inquiry Widget"; its deliverable is the triage API, and it keeps a test page used only for manual verification.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of user- and operator-facing name references resolve to "Inquiry Handler"; zero stale "Inquiry Widget" references remain in the service's documentation, interface, or configuration naming.
- **SC-002**: 100% of triage API requests that succeeded before the rename succeed after it with identical request/response contracts (verified against the documented contract).
- **SC-003**: The test page is recognizable as a test console at a glance and returns one of the three dispositions for a manual inquiry.
- **SC-004**: The full automated test suite passes with zero regressions and no reduction in assertion coverage.
- **SC-005**: The service comes up healthy under the renamed deployment identity, and its health endpoint responds as before.

## Assumptions

- The rename is comprehensive: every place the service's name/identity is presented — interface, documentation, configuration, and deployment naming — moves to "Inquiry Handler", including the on-disk service location and Docker Compose service/container naming, since nothing else in the stack addresses the service by name.
- The public triage API contract is frozen: endpoint path, JSON request/response shapes, status codes, and the three dispositions are unchanged. No breaking interface change.
- The web page stays at its current URL and is simply relabeled as the Inquiry Handler test console; it is retained for manual testing (per the user: the widget is "only there for testing").
- Triage business rules are unchanged — this is a purely nominal rename; no enrichment of behavior, no removal of dispositions, no change to failure handling.
- The health endpoint and Compose healthcheck behavior stay the same.
- No data-model or schema changes; the service remains stateless with no database/queue/cache backend.
- The rename-surface definition treats user- and operator-facing references as the completion bar; internal comments and cache artifacts are tidied opportunistically, not gate-keeping.