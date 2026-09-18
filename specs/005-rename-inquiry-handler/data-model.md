# Data Model: Rename Inquiry Widget to Inquiry Handler

**Date**: 2026-09-13
**Source**: [spec.md](./spec.md) (Key Entities), [research.md](./research.md)

## 005 introduces no new persistent data

This feature is a **purely nominal rename**. Every entity the service already works with — the transient `inquiry_payload`, `inquiry`, `retrieved_context`, and `triage_outcome` objects of feature 004 (documented in detail in [specs/004-ai-inquiry-triage/data-model.md](../../004-ai-inquiry-triage/data-model.md)) — is **unchanged** in shape, validation, and lifecycle. The service remains a stateless HTTP-only consumer (constitution I/IV): no tables, no migrations, no new storage. The only long-lived state is still the in-memory service-account bearer token cache (`app/Support/ServiceToken.php`), which is untouched by the rename.

The outward HTTP payload contract is **frozen** (spec FR-002): request/response JSON shapes, status codes, and the three dispositions (`decline | escalate | booking`) stay byte-for-byte identical (see [contracts/inquiry-web.md](./contracts/inquiry-web.md)).

## Identity: the only "model" this feature changes

The song point of this feature is the service **identity** — a single canonical name used consistently across every user- and operator-facing surface (spec FR-001, SC-1).

| Identity | Value (new) | Deprecated (old) |
|----------|-------------|------------------|
| Service name (canonical) | **Inquiry Handler** | Inquiry Widget |
| Deployment/service + container key | `inquiry-handler` | `inquiry-widget` |
| Package name (composer) | `sales-ops/inquiry-handler` | `sales-ops/inquiry-widget` |
| App label (`APP_NAME`) | `Inquiry Handler` | `Sales Inquiry Widget` |
| Web page role/heading | **Test console** ("Inquiry Handler — Test Console") | Customer-facing "widget" |

**Terminology rules**:
- Use **Inquiry Handler** for the service; use **test console** for the `GET /` page (it is a verification aid, never a product surface — spec FR-003).
- Retain "widget" only when referring to the former framing or the deprecated identity; otherwise avoid it (spec SC-1, research §1).

## Canonical entities (unchanged, referenced from 004)

| Entity | Status | Where defined |
|--------|--------|---------------|
| `inquiry_payload` / `inquiry` / `retrieved_context` / `triage_outcome` | Unchanged (transient, per request) | [004 data-model.md](../../004-ai-inquiry-triage/data-model.md) |
| In-memory service-account token | Unchanged | `app/Support/ServiceToken.php` (004) |
| `/health` liveness | Unchanged | `app/Http/Controllers/HealthController.php` |

The model of this feature is `rename(identity surfaces) → consistent "Inquiry Handler" naming`, with zero rows, zero tables, and zero migrations.