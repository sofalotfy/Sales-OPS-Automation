# RAG Query Consumption Contract (existing endpoint)

This document records how the inquiry widget consumes the **existing** `work-scope-rag` semantic retrieval endpoint. No change to the RAG service is made by this feature (research §1). Original definition: [document-api.md](../../001-rag-document-service/contracts/document-api.md) — see also [rag-auth-guard.md](../../002-api-user-auth/contracts/rag-auth-guard.md) for the bearer guard every route sits behind.

**Base URL** in the Compose network: `http://work-scope-rag:8000`.

## `POST /query` — retrieve relevant chunks (FR-003)

Request body (JSON):

```json
{ "query": "Do you offer annual maintenance contracts for heating boilers?", "top_k": 5 }
```

- `query`: required STRING, non-blank after trim.
- `top_k`: optional integer, 1–20, defaults to 5 (the widget sends `RAG_TOP_K`).
- `filters`: optional dict (not used by the widget in v1).

**Response `200`**:

```json
{
  "success": true,
  "query": "Do you offer annual maintenance contracts for heating boilers?",
  "result_count": 3,
  "results": [
    {
      "rank": 1,
      "text": "Annual maintenance plans cover ...",
      "similarity_score": 0.72,
      "document_id": "6f0c8f2a-...-uuid",
      "source": "playbooks-2026",
      "title": "Maintenance Plans",
      "chunk_index": 4
    }
  ]
}
```

**How the widget uses it (data model: `retrieved_context`)**:
- send `query` = extracted `message`, `top_k` = `RAG_TOP_K`;
- pass `results[]` (title/source/text per chunk, ranks, scores) verbatim as data into `PromptBuilder` (never as instructions — FR-012);
- `result_count == 0` is a legitimate outcome (no in-scope context); still forwarded to the AI, which should escalate on no-context per the system prompt.

**Error cases the widget handles**

| Code | Widget action |
|------|---------------|
| 200, 1+ results | Build context, call the AI. |
| 200, 0 results | Send AI call with the explicit "no retrieved documents" block. |
| 401 | Refresh service-account token once (research §2), retry once; if still 401, fail → escalate-by-default. |
| 404/422 | Treat as a triage-request error → escalate-by-default (context = the extracted inquiry). |
| 503 / timeout | Fail → escalate-by-default (or 503 per [inquiry-web.md](./inquiry-web.md) if context cannot be preserved). |

Responses are NOT thrown on; the `RagApiClient` returns the `Http` Response and the triage flow inspects status + body (mirrors `dashboard/app/Services/RagApiClient.php` conventions).