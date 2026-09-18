# Feature Specification: Weighted Multi-Factor Inquiry Classification

**Feature Directory**: `006-weighted-factor-classification`

**Feature Branch**: `006-weighted-factor-classification`

**Created**: 2026-09-13

**Status**: Draft

**Input**: User description: "start working on creating the two models we just talked about and make space and maintanable code design to add the features as services with defined interface t oadd factors"

## Clarifications

### Session 2026-09-13

- Q: How should factor weights be updated at runtime? → A: Through a tab/resource added to the dashboard service, where an admin edits the weights; the dashboard reaches the weights via this service's HTTP interface (never the scoped store directly).
- Q: When the factor catalog is empty, which classification is returned as the fallback? → A: `low`.
- Q: When a factor cannot compute its value, what should it contribute to the combined score? → A: The failing factor is dropped and the remaining weights are renormalized; the final score reflects only the factors that computed.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Every Inquiry Gets a Weighted Score and a Classification (Priority: P1)

Instead of a single yes/no or three-way decision, each sales inquiry is judged by several independent factors. Every factor contributes a score with its own weight, the contributions are combined into one final score, and that final score is reported as one of four classifications: **high**, **medium**, **low**, or **disqualify**. The operator sees both the single classification and the per-factor breakdown so they can judge *why* an inquiry was graded the way it was.

**Why this priority**: This is the core purpose of the change — replacing the current single-shot classification with a transparent, multi-factor one.

**Independent Test**: Submit a test inquiry and confirm the response contains exactly one of the four classifications, a numeric final score, and a breakdown of the score of every factor that ran.

**Acceptance Scenarios**:

1. **Given** a valid inquiry, **When** it is submitted for classification, **Then** the response contains exactly one classification: high, medium, low, or disqualify.
2. **Given** a valid inquiry, **When** it is submitted for classification, **Then** the response contains a single numeric final score that is consistent with the per-factor scores and weights.
3. **Given** a valid inquiry, **When** it is submitted for classification, **Then** the response lists each factor that contributed, its score, and its weight.

---

### User Story 2 - Factors Are Defined as Pluggable Services Behind a Fixed Interface (Priority: P1)

A factor is an independent unit of judgment with its own logic — for example "how relevant to our scope", "how clearly needs action", "urgency". Each factor is implemented as its own service that exposes a fixed, well-defined interface: a factor has a name and can compute a score from the inquiry and its supporting context. New factors are added by defining a new service and registering it — no changes to the rest of the classification flow. The set of factors is decided in development; it is not changed at runtime.

**Why this priority**: This is the stated goal of making the design "maintanable" — the classification logic becomes additive, not a growing single decision routine.

**Independent Test**: Add one new factor service, register it, and confirm it is picked up by the combining step without touching any other logic.

**Acceptance Scenarios**:

1. **Given** a defined factor interface, **When** a developer implements a new factor, **Then** it runs and its score is included in the breakdown without modifying the combining logic.
2. **Given** a registered factor list, **When** a developer renames or removes a factor, **Then** this only ever happens in development, not through runtime controls.

---

### User Story 3 - Two Models Persist the Weights and Every Classification (Priority: P1)

Two records exist: (1) a **factor settings** record that stores the current weight of every factor keyed by factor name, and (2) a **classification result** record that stores, for every inquiry classified, all the factor scores, the final score, the classification, and the surrounding context. Weights are editable at runtime and the next classification uses the new weights immediately. Each classification is logged so it can be reviewed or audited later.

**Why this priority**: These are the two requested models; they are the persistence backbone of the scoring system.

**Independent Test**: Change a factor's stored weight and submit an inquiry — the final score must reflect the new weight; then confirm the full classification was saved.

**Acceptance Scenarios**:

1. **Given** an existing factor settings record, **When** a factor's weight is changed, **Then** the very next classification uses the updated weight without a redeploy.
2. **Given** a submitted inquiry, **When** a classification completes, **Then** a result record is stored containing the per-factor scores, the final score, and the classification.
3. **Given** a stored classification result, **When** it is later reviewed, **Then** it contains enough detail (scores, weights, context, scores breakdown) to reconstruct how the final decision was reached.

---

### User Story 4 - Classification Survives Missing Data and Failures (Priority: P2)

The classification must always complete with a result. If no factors are registered yet, if a single factor cannot compute its value (for example its data source or model is unavailable), or if the log store cannot be written, the inbound inquiry still gets a valid classification — it is never dropped or rejected because a helper failed.

**Why this priority**: Failures must not break the pipeline; humans review the classification output, so a degraded-but-produced result is preferred over a failed one.

**Independent Test**: Force one factor's source to fail, force an empty factor list, and force the log write to fail — in each case a valid classification is still returned.

**Acceptance Scenarios**:

1. **Given** a factor that cannot compute its value, **When** classification runs, **Then** that factor is dropped and the remaining factors still count with renormalized weights.
2. **Given** no factors registered, **When** classification runs, **Then** the `low` fallback classification is still returned.
3. **Given** the log store is unavailable, **When** classification runs, **Then** the inbound response is still delivered and the failure is recorded.

---

### User Story 5 - Existing Classification Code Is Kept, Tagged, and Not Wired In (Priority: P3)

The current single-shot classification logic (its dispositions, its dispatch step, its handlers, and its prompt builder) is not deleted. Any part that is no longer used by the new scoring flow remains in the codebase, clearly marked as unused/superseded, but is not invoked by the new path.

**Why this priority**: Reuse-what-works and low-risk migration; nothing is lost that might still be referenced or useful.

**Independent Test**: Search the codebase for the retired classification components and confirm they still exist, carry a deprecation/unused marker, and are never called by the new scoring flow.

**Acceptance Scenarios**:

1. **Given** the pre-existing classification code, **When** the new scoring flow is introduced, **Then** all existing classes remain present and marked as deprecated/unused.
2. **Given** a retired component, **When** the new flow runs, **Then** it is never invoked by the new flow.

---

### User Story 6 - Admin Adjusts Factor Weights From the Dashboard (Priority: P2)

An admin opens a tab/resource in the dashboard service, sees the current weight of every factor, and edits the weights. The change is applied by the very next classification request, with no redeploy and no database shell. The dashboard reads and writes the weights only through this service's HTTP interface — it never connects to the inquiry-handler's store directly, so the store stays scoped to this service.

**Why this priority**: This is the chosen mechanism for the runtime-editable weights requirement — the operator-facing control surface.

**Independent Test**: From the dashboard tab, change a factor's weight, then submit an inquiry to the classification endpoint and confirm the new weight is reflected in the final score.

**Acceptance Scenarios**:

1. **Given** the dashboard tab, **When** an admin opens it, **Then** every registered factor's current weight is listed.
2. **Given** the dashboard tab, **When** an admin saves a new weight, **Then** the next classification request uses the new weight.
3. **Given** the scoped store, **When** the dashboard reads or updates weights, **Then** it does so through this service's HTTP interface, never by direct access to the store.

---

### Edge Cases

- No factors are registered (empty catalog) — a `low` classification is still produced, never an error or a missing result.
- A factor's score source fails (model/retrieval unavailable, bad response, timeout) — that factor is dropped and the remaining factors' weights are renormalized instead of aborting.
- A registered factor has no stored weight — a documented default weight applies.
- Stored weights do not sum to any particular total — the combination normalizes them so the final score is always in a fixed range.
- A factor reports a score outside the valid range — it is clamped into the range before combining.
- The final score lands exactly on a threshold boundary — a stable, predefined rule decides which classification wins.
- Unknown or duplicate factor names appear in the settings record — they are ignored and never double-counted.
- The classification log write fails — the visitor-facing result is still returned and the failure is recorded.
- Contact fields are absent — classification proceeds on the message alone.
- No retrieved context is available — factors that depend on context use their no-context fallback; classification still completes.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system SHALL return exactly one classification per inquiry from the fixed set: **high**, **medium**, **low**, **disqualify**.
- **FR-002**: Each factor SHALL be implemented as an independent service exposing a fixed interface with a factor name and a method to compute its score from the inquiry and supporting context.
- **FR-003**: The system SHALL combine the per-factor weighted scores into a single final score in a fixed range and map that score to a classification through thresholds.
- **FR-004**: The set of factors SHALL be changeable only in development; the stored weights SHALL be the only runtime-editable part.
- **FR-005**: An admin SHALL be able to edit factor weights from a tab/resource in the dashboard service, and a stored weight SHALL take effect on the next classification without redeploying.
- **FR-006**: A registered factor without a stored weight SHALL use a defined default weight.
- **FR-007**: A factor that cannot compute its value SHALL be dropped from the combination, and the weights of the remaining factors SHALL be renormalized so the final score reflects only the factors that computed.
- **FR-008**: Classification SHALL complete with the `low` fallback result when the factor catalog is empty.
- **FR-009**: The system SHALL persist a classification result for every inquiry, containing the per-factor scores, weights, final score, classification, and the context used.
- **FR-010**: The persistence store for factor settings and classification results SHALL be dedicated to this service, SHALL be reachable by other services only through this service's HTTP interface, and SHALL NOT be accessed or initialized directly by any other service.
- **FR-011**: Every inquiry SHALL remain visible to a human reviewer regardless of its classification; classification SHALL inform human review and never hide an inquiry.
- **FR-012**: Superseded classification logic SHALL remain present in the codebase, SHALL be clearly marked as unused/deprecated, and SHALL NOT be invoked by the new scoring flow.
- **FR-013**: Classification logic SHALL require no shared state between requests; each inquiry is classified independently.

*Note: FR-013 keeps the classification stateless on the request path (the weights record is read but never written during a request), consistent with the current stateless HTTP behavior.*

### Key Entities

- **Factor Settings**: A single record mapping each factor's key (name) to its current weight. Read on every classification; only weights are runtime-editable (via the dashboard tab, through this service's HTTP interface); the factor set itself is defined in code.
- **Classification Result**: A per-inquiry record storing the inquiry message, contact fields when present, the context used, one entry per factor with its score and weight, the final score, the classification, any reasoning, and the timestamps.
- **Classification**: The four-valued outcome set — high, medium, low, disqualify — reported per inquiry and stored on each classification result.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of valid inquiries receive exactly one of the four classifications with a numeric final score.
- **SC-002**: 100% of completed classifications are persisted with per-factor scores and the final result.
- **SC-003**: A developer can add a new factor by defining one service and registering it; no other code change is required.
- **SC-004**: An admin can change a weight from the dashboard tab, and the change is reflected in the classification produced by the next request, with no redeploy.
- **SC-005**: Classification completes with a valid outcome even when one factor's source, the retrieval layer, or the log store is unavailable.
- **SC-006**: The dedicated store is accessible only through this service; the dashboard reads/updates weights via this service's HTTP interface and no other service configures a direct connection to it.
- **SC-007**: 100% of superseded classification classes remain present and are findable as unused/deprecated; the new flow never invokes them.

## Assumptions

- The dedicated store is a separate database within the project's shared database infrastructure; it is reachable by the dashboard only through this service's HTTP interface, and its credentials are exposed only to this service.
- The dashboard gains a tab/resource for admins to view and edit factor weights; the dashboard does not connect to the inquiry-handler store directly.
- Automated tests use a throwaway store (for example in-memory), never the scoped live store.
- The factor catalog begins empty (empty catalog returns a `low` fallback classification) and factors are added one at a time in development.
- AI is used *by factors*: a factor's service may consult a model to compute its value. There is no single shared model call for the whole classification.
- Visitor-facing reply copy for each classification is a developer-chosen placeholder for now and will be confirmed with the sales team later.
- Human review of every inquiry is the operating model; classification is advisory signal, not a gate that hides inquiries.
- Weights live on a single factor-settings record; weights may be non-normalized and are normalized during combination.