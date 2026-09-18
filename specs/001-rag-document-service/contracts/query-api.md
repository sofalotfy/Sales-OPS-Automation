# Query API Contract

Base URL: `http://<host>:8000` · `Content-Type: application/json`

**Errors**: JSON `{"detail": "<message>"}`. Invalid queries return `422`; empty queries are rejected (FR-9).

## `POST /query` — Semantic retrieval (FR-4, FR-5, FR-14)

Embeds the query and returns the most relevant passages from the vector store, ranked by cosine similarity, honoring requested result count and optional metadata filters.

**Request**

```json
{
  "query": "What services does Robusta offer for inbound sales?",
  "top_k": 5,
  "filters": { "source": "playbooks" }
}
```

- `query`: non-empty string (required).
- `top_k`: integer 1–20, default 5 (capped at 20).
- `filters`: optional object of metadata key/value pairs; when present, only chunks whose document metadata matches are eligible.

**Response `200`**

```json
{
  "success": true,
  "query": "What services does Robusta offer for inbound sales?",
  "result_count": 5,
  "results": [
    {
      "rank": 1,
      "text": "Robusta's inbound sales workflow triages...",
      "similarity_score": 0.84,
      "document_id": "6f0c8f2a-...-uuid",
      "source": "playbooks",
      "title": "Sales Playbook 2026",
      "chunk_index": 3
    }
  ]
}
```

- `similarity_score`: float in `[0, 1)` (1 − cosine distance). Serialized as a JSON float.
- Results sorted by descending score; only documents with `status = ready` are searched.

**No-match behavior** (FR-5 edge case): when all scores fall below the service's relevance threshold, the response is an explicit empty result set so callers can tell "no relevant content found" from a failure:

```json
{
  "success": true,
  "query": "...",
  "result_count": 0,
  "results": []
}
```

**Error cases**

| Code | Condition |
|------|-----------|
| 422 | `query` empty or not a string; `top_k` out of range; `filters` not an object; malformed JSON. |
| 500 | Embedding or store failure (surfaced via `detail`, triage layer escalates per human-in-the-loop). |

## Relevance threshold note

Threshold is an internal service setting (default: excluded from the API surface unless explicitly configured for a deployment). Callers use the presence of results + scores to decide escalation; the threshold itself is plumbing, not consumer input.