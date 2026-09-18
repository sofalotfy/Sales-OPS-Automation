# Inquiry Widget Web Contract

**Version**: 0.2.0 (this feature, post-clarification)
**Service**: `inquiry-widget`, port `8003` (Docker Compose network: `http://inquiry-widget:8003`; locally `http://localhost:8003`).
**Purpose**: The public surface of the AI sales inquiry widget (spec US-1..US-5).

## `GET /`

**Response `200`** — the widget page. An HTML document (Blade layout) rendering:
- a **required message** input field for the visitor's sales inquiry;
- an **optional name** input field;
- an **optional email** input field (validates format if filled);
- a submit control (fetch to `POST /inquiry/triage`);
- a result area that shows the returned `reply` for any disposition.

No assets external to the service; nothing server-side is required beyond the static page.

## `POST /inquiry/triage`

Triages a single visitor sales inquiry end-to-end: extract → RAG → AI → disposition → widget result.

**Request body** (JSON, `Content-Type: application/json`):

```json
{
  "message": "Do you offer annual maintenance contracts for heating boilers?",
  "name": "Jane Doe",
  "email": "jane.doe@example.com"
}
```

- `message`: required STRING, non-blank after trim, ≤ `MESSAGE_MAX_LENGTH` (default 4000) chars.
- `name`: optional STRING, ≤ 255 chars.
- `email`: optional STRING, valid email format if provided.

**Response `200`** — triage result (uniform for all three dispositions):

```json
{
  "disposition": "booking",
  "reply": "Yes — we have annual maintenance plans. You can book a slot here: https://calendly.com/...",
  "reasoning": "In-scope sales inquiry, meeting-ready intent.",
  "context": {
    "inquiry": {
      "message": "Do you offer annual maintenance contracts ...?",
      "name": "Jane Doe",
      "email": "jane.doe@example.com"
    },
    "retrieved_context": { "result_count": 5, "results": [] }
  }
}
```

- `disposition`: exactly one of `decline | escalate | booking`. (FR-005)
- `reply`: the visitor-facing message.
- `reasoning`: internal justification.
- `context.inquiry`: the normalized input (message + optional contact fields as received).
- `context.retrieved_context`: the RAG grounding (present on all dispositions).

**Error cases**

| Code | Condition |
|------|-----------|
| 400 | Body not a JSON object / `message` not a string. |
| 422 | `message` missing, blank after trim, or over `MESSAGE_MAX_LENGTH`; or `email` provided but not a valid email format. Detail explains the rule. |
| 503 | Triage could not be completed (RAG `POST /query` down, auth-service unreachable, Groq unreachable) **and** the escalate-by-default path itself failed (e.g. no way to reach the RAG service to build context). Body: `{"detail": "Triage service unavailable. Please try again shortly."}` A clear user message per spec FR-014. |
| 500 | Unexpected internal error (never a fabricated disposition). |

**Guarantees**

- Exactly one disposition per valid inquiry, or a clear error — never a silent drop (FR-005, FR-014).
- Any ambiguity/failure inside triage resolves internally to `escalate` (not to an HTTP error) whenever context can be preserved (FR-009/FR-010).
- No cookies/session required: stateless per request; the plan explicitly does not depend on the visitor being authenticated.
- The visitor's message, name, and email are treated as data only (FR-012) — the widget never renders or executes anything derived from them as markup/instructions.