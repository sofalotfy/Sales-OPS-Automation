# RAG Service API Protection Contract

How the existing RAG service enforces the new auth feature on its public API. This is an **amendment to** [document-api.md](../../001-rag-document-service/contracts/document-api.md) and [query-api.md](../../001-rag-document-service/contracts/query-api.md) — their request/response shapes are unchanged; a mandatory auth layer now wraps them.

**Issuer**: `auth-service` · **Verification**: `GET /auth/verify` introspection, per request, un-cached → exact matching of [auth-api.md](./auth-api.md).

## Protected vs public routes

| Route | Protection |
|-------|-----------|
| `POST /documents` | Protected |
| `GET /documents` | Protected |
| `PUT /documents/{document_id}` | Protected |
| `DELETE /documents/{document_id}` | Protected |
| `POST /query` | Protected |
| `GET /health` | **Public** (FR-8 — probing must keep working without a token) |
| `GET /` → `/docs` | Public (documentation; no data operations) |

## Request behavior

Callers present `Authorization: Bearer <token>` on protected routes. The RAG service:

1. Reads the `Authorization` header.
2. Forwards it to `auth-service GET /auth/verify`.
3. On `200` → runs the requested operation (user context available but unused for data gating; the RAG service has a single access tier).
4. On `401` from verify → rejects the request without performing it.
5. On auth-service **unreachable/error** → rejects with `503` (fail closed; do not silently allow).

## Response codes introduced by the guard

| Code | Condition |
|------|-----------|
| 401 | Missing, unknown, expired, or revoked token; disabled/absent user. Body: `{"detail": "Not authenticated."}`. |
| 403 | Reserved for future role-based gating (not used until the RAG service needs roles). |
| 503 | Auth service unreachable or misbehaving; the request was *not* performed. Body: `{"detail": "Authorization service unavailable."}` |

## Guarantees this layer provides

- **100% of protected routes** refuse missing/invalid/expired/revoked tokens and never execute the underlying operation (spec SC-2).
- **Immediate disablement**: an administrator's `PATCH /auth/users/{id} {is_enabled: false}` blocks the user's very next request because verification is per-request with no cache (spec SC-3).
- **No bypass via `/docs` or redirects**: documentation routes expose no data operations; only `GET /health` and `GET /` remain open.

## Quickstart reference

One-line smoke: `curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/documents` → `200`; without the header → `401`. Full scenarios in [quickstart.md](../quickstart.md).