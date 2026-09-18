# AI Provider Contract (Groq Chat Completions)

This documents the outbound AI call made by `inquiry-widget` (research §3, §4, §5). The provider is **Groq** hosting an open-source model; the key and model come from env (`GROQ_API_KEY`, `GROQ_MODEL`, FR-011). All AI access is isolated behind `app/Services/AiCallingService` so the provider model can be swapped by changing env vars.

**Endpoint**: `https://api.groq.com/openai/v1/chat/completions` (OpenAI-compatible), `Authorization: Bearer {{GROQ_API_KEY}}`, `Content-Type: application/json`.

## Request (as constructed by `AiCallingService`)

```json
{
  "model": "llama-3.3-70b-versatile",
  "temperature": 0,
  "response_format": { "type": "json_object" },
  "messages": [
    { "role": "system", "content": "<fixed system prompt — PromptBuilder constant>" },
    { "role": "user", "content": "<user inquiry + retrieved documents as data blocks>" }
  ]
}
```

On request-level validation (e.g. `422` from Groq due to model name / unsupported `response_format`), fall back to a second call with `"response_format"` omitted — models are not uniform on JSON-mode support.

## System prompt boundary (FR-004 / FR-012 / US-1)

`PromptBuilder` is the **only** place the prompt is assembled and MUST keep three parts strictly separated:

1. **Fixed system prompt** — constant string: company scope, triage rules (decline/escalate/booking), and the strict JSON output contract below.
2. **`USER INQUIRY` block** — the extracted `message`, delimited (`[USER INQUIRY] ... [/USER INQUIRY]`), presented as content to be judged.
3. **`RETRIEVED DOCUMENTS` block** — the RAG `results[]` (title/source/text/rank per chunk), delimited, presented as reference data. If no documents were retrieved, the block explicitly states "no retrieved documents" and the prompt instructs the AI to escalate rather than invent facts.

Neither block may alter the system prompt; visitor/retrieved content is data (FR-012). Unit tests on `PromptBuilder` assert the three blocks exist and that the fixed prompt is a constant free of any request-derived text.

## Output contract (STRICT — the triage contract)

The model MUST respond with a single JSON object (Groq JSON mode + prompt instruction):

```json
{
  "disposition": "decline",
  "reply": "Polite visitor-facing message.",
  "reasoning": "Internal justification."
}
```

- `disposition`: one of `decline | escalate | booking` (FR-005). Anything else = failure.
- `reply`: STRING, what the visitor sees (may be empty for `escalate`, where the widget shows "we'll put you in touch" or similar).
- `reasoning`: STRING, internal.

## Parsing / validation rules (`AiCallingService` + `Dispatcher`)

| Condition | Action |
|-----------|--------|
| Valid JSON with `disposition` ∈ {decline, escalate, booking}, `reply`/`reasoning` strings | Dispatcher routes to the matching handler. |
| Valid JSON but unknown/`null`/non-string `disposition` | **Escalate-by-default** (context preserved). |
| Non-JSON body, or JSON-decoding failure | **Escalate-by-default**. |
| Groq HTTP error (`>= 400`), timeout, connection error, missing `GROQ_API_KEY` | **Escalate-by-default**. |
| HTTPS/TLS or non-2xx after JSON-mode retry fallback | **Escalate-by-default**. |

Escalate-by-default is non-negotiable (constitution III; FR-009/FR-010). `Dispatcher` maps `decline → DeclineHandler`, `escalate → EscalateHandler`, `booking → BookingHandler`; **any** other value routes to `EscalateHandler`. `BookingHandler` additionally re-checks `BOOKING_URL` is configured — if absent, it escalates rather than returning a broken link (FR-009).

Return value of `AiCallingService`: a normalized struct `{disposition, reply, reasoning, raw_ok: bool}` that `Dispatcher` can act on deterministically with no further model access.