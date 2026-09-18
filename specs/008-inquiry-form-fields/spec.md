# Feature Specification: Inquiry Form Fields

**Feature Branch**: `008-inquiry-form-fields`

**Created**: 2026-09-15

**Status**: Draft

**Input**: User description: "change the inquiry form to take (first name, last name, email, phone number, company name, country/region and message)"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Submit Inquiry with Contact Details (Priority: P1)

A visitor on the sales page fills out the inquiry form with their first name, last name, email, phone number, company name, country/region, and a message describing their needs, then submits the form and receives a classification response.

**Why this priority**: This is the core form interaction — the entire inquiry flow depends on collecting these fields from the user.

**Independent Test**: Can be fully tested by loading the inquiry page, filling all fields, and submitting. Delivers a working inquiry form that captures structured contact information.

**Acceptance Scenarios**:

1. **Given** the inquiry form is loaded, **When** the visitor fills in first name, last name, email, phone number, company name, country/region, and message, and submits, **Then** the form sends all fields to the triage endpoint and displays the classification response.
2. **Given** the inquiry form is loaded, **When** the visitor submits with only first name, last name, email, and message (leaving phone, company, country blank), **Then** the submission succeeds and the optional fields are accepted as empty.
3. **Given** the inquiry form is loaded, **When** the visitor submits without first name, last name, email, or message, **Then** the form displays validation errors indicating these fields are required.

---

### User Story 2 - Persist Contact Fields with Inquiry Record (Priority: P2)

Each submitted inquiry stores the full set of contact fields (first name, last name, email, phone number, company name, country/region) alongside the inquiry message in the classification results log, so the sales team can follow up with complete contact information.

**Why this priority**: Without persisting the new fields, the sales team loses the contact data needed to act on inquiries.

**Independent Test**: Can be tested by submitting an inquiry and verifying all field values are stored in the classification results database record.

**Acceptance Scenarios**:

1. **Given** an inquiry is submitted with all fields populated, **When** the classification result is persisted, **Then** the record contains first name, last name, email, phone number, company name, country/region, and message.
2. **Given** an inquiry is submitted with optional fields empty, **When** the classification result is persisted, **Then** the optional fields are stored as null values.

---

### User Story 3 - View Stored Contact Fields via Admin (Priority: P3)

An admin views the classification results list and detail pages, where each inquiry record displays the submitter's first name, last name, email, phone number, company name, and country/region alongside the classification outcome.

**Why this priority**: The admin needs visibility into the stored contact data to act on inquiries and verify data capture.

**Independent Test**: Can be tested by submitting an inquiry and then viewing the admin classification results page to confirm all contact fields are displayed.

**Acceptance Scenarios**:

1. **Given** an inquiry with contact details has been persisted, **When** the admin views the classification results list, **Then** the first name, last name, and email are visible for that record.
2. **Given** an inquiry with contact details has been persisted, **When** the admin views the detail page for that record, **Then** all contact fields (first name, last name, email, phone number, company name, country/region) are displayed.

---

### Edge Cases

- What happens when a visitor submits with a phone number in an unexpected format (e.g., letters, special characters)? — Accept any string up to the max length; no strict phone format validation.
- What happens when a visitor enters an extremely long value in an optional text field? — Reject with a max-length error (255 characters).
- What happens when the email provided is already associated with an existing inquiry? — Allow duplicate submissions; no uniqueness constraint on email.
- What happens when a visitor submits with an invalid email format? — Reject with a validation error indicating the email must be valid.
- How does the system handle a country/region value that doesn't match a known country? — Accept any string the user provides; no enumeration or lookup validation.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST present an inquiry form with fields for first name, last name, email, phone number, company name, country/region, and message.
- **FR-002**: System MUST require first name, last name, email, and message upon form submission.
- **FR-003**: System MUST accept phone number, company name, and country/region as optional fields.
- **FR-004**: System MUST validate that email values are in a valid email format.
- **FR-005**: System MUST enforce a maximum length of 255 characters on first name, last name, email, phone number, company name, and country/region fields.
- **FR-006**: System MUST enforce a maximum length of 4000 characters on the message field.
- **FR-007**: System MUST persist all seven fields (first name, last name, email, phone number, company name, country/region, message) with each classification result record.
- **FR-008**: System MUST replace the existing single name field with separate first name and last name fields across the form, validation, persistence, and admin display layers.
- **FR-009**: System MUST display all stored contact fields on the admin classification results detail page.

### Key Entities

- **Inquiry Submission**: Represents a visitor's inquiry, containing first name (required), last name (required), email (required), phone number (optional), company name (optional), country/region (optional), and message (required).
- **Classification Result**: The persisted record of an inquiry after triage, containing all inquiry fields plus classification outcome, scores, reasoning, and scope check data.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Users can complete and submit the inquiry form with all seven fields in under 2 minutes.
- **SC-002**: 100% of submitted inquiry records contain first name, last name, email, and message values.
- **SC-003**: Form validation rejects submissions missing any required field and displays a clear error message within 1 second.
- **SC-004**: Admin users can view all stored contact fields for any inquiry on the detail page without navigating away from the classification results section.

## Assumptions

- The existing single `name` field is being intentionally split into `first_name` and `last_name`; there is no backward-compatibility requirement for the old field name in new submissions.
- Phone number validation is intentionally lenient — the system accepts any string up to 255 characters rather than enforcing a specific phone format, to accommodate international numbers.
- No existing data migration is needed for historical classification result records that contain the old `name` column.
- The country/region field is free-text, not a dropdown or validated against a list of countries.
- Email remains the primary unique identifier for follow-up; no deduplication logic is required.
- The inquiry form continues to be served at the root path (`/`) and the triage endpoint remains `POST /inquiry/triage`.
