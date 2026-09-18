# Inquiry Handler Web Contract

**Version**: 1.0.0 (005 rename — nominal, contract frozen)
**Service**: `inquiry-handler`, port `8003` (Docker Compose network: `http://inquiry-handler:8003`; locally `http://localhost:8003`). Formerly `inquiry-widget` — that name no longer resolves (hard cut, 005 clarifications).
**Purpose**: The outward surface of the **Inquiry Handler** (spec US-1..US-4). The triage API is the primary, supported interface; the `GET /` page is a **test console** for manual verification only (FR-003).

## `GET /` — test console (not a product surface)

**Response `200`** — a single self-contained HTML page (Blade layout), visibly labeled **"Inquiry Handler — Test Console"** (spec SC-3). It renders:
- a **required message** input field;
- an **optional name** input field;
- an **optional email** input field (browser-validated; server enforces format);
- a submit control (fetch to `POST /inquiry/triage`);
- a result area rendering the returned `reply` for any disposition.

This page is for developers/testers manually exercising the triage API. It is not customer-facing. Frame and form behavior are unchanged from the 004 widget page (006 supersedes it in identity only).

## `POST /inquiry/triage`

Triages a single sales inquiry end-to-end: extract → RAG → AI → disposition → result. **Contract frozen** vs the 004 definition (spec FR-002).

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

**Response `200`** — uniform triage result for all three dispositions:

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

- `disposition`: exactly one of `decline | escalate | booking`.
- `reply`: the inquirer-facing message.
- `reasoning`: internal justification.
- `context.inquiry`: the normalized input (message + optional contact fields).
- `context.retrieved_context`: the RAG grounding (present on all dispositions).

**Error cases**

| Code | Condition |
|------|-----------|
| 400 | Body not a JSON object / `message` not a string. |
| 422 | `message` missing, blank after trim, or over `MESSAGE_MAX_LENGTH`; or `email` provided but not a valid format. |
| 503 | Triage could not be completed (RAG down, auth-service unreachable, AI provider unreachable) and the escalate-by-default path itself failed. Body: `{"detail": "Triage service unavailable. Please try again shortly."}` |
| 500 | Unexpected internal error (never a fabricated disposition). |

**Guarantees**

- Exactly one disposition per valid inquiry, or a clear error — never a silent drop.
- Any ambiguity/failure inside triage resolves internally to `escalate` whenever context can be preserved.
- No cookies/session/authentication required: stateless per request.
- Visitor message/name/email are data only — never rendered or executed as markup/instructions.

## `GET /health`

**Response `200`** — liveness probe for the Compose healthcheck, unchanged: `{"status": "ok"}` (or equivalent).

## Outbound contracts (unchanged)

The service consumes the existing `work-scope-rag POST /query` and `auth-service POST /auth/login` endpoints exactly as in feature 004 — [rag-query.md](../../004-ai-inquiry-triage/contracts/rag-query.md) and [ai-provider.md](../../004-ai-inquiry-triage/contracts/ai-provider.md) remain the governing documents (research.md). No outbound change is made by this rename.