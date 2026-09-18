# Implementation Plan: Rename Inquiry Widget to Inquiry Handler

**Branch**: `005-rename-inquiry-handler` | **Date**: 2026-09-13 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/005-rename-inquiry-handler/spec.md`

## Summary

Comprehensive, purely nominal rename of the Laravel triage service delivered by feature 004 as `inquiry-widget`. The service identity becomes **Inquiry Handler** at every user- and operator-facing surface (FR-001, FR-007). The triage API stays the primary interface with a **frozen contract** — `POST /inquiry/triage` plus `GET /health` are byte-for-byte unchanged (FR-002, FR-006). The `GET /` page stays at its current URL and is relabeled as the **Inquiry Handler test console** (FR-003; per Clarifications 2026-09-13). The deployment identity renames `inquiry-widget` → `inquiry-handler` with a **hard cut** — the old name stops resolving and no alias is kept (FR-004; per Clarifications). Triage rules (decline/escalate/booking, escalate-by-default) are untouched (FR-005); the full automated test suite stays green with no reduction in coverage (FR-008). Historical `specs/004-*` documents are left as the archival record (user decision) — `005` supersedes them for go-forward operations.

Research decisions (see [research.md](./research.md)): mechanical directory rename preserving the untracked `.env` and `.gitignore`; completion bar = the spec SC-001 user/operator naming surfaces (internal comments tidied opportunistically, not gate-keeping, per spec Edge Cases); test-page copy is **"Inquiry Handler — Test Console"**; URLs frozen (no path moves); the internal route name `widget` → `inquiry.test-console`; `composer.lock` regenerated and compiled view caches rebuilt by the framework.

## Technical Context

**Language/Version**: PHP 8.4, Laravel 13.x — unchanged. The service is renamed in place; neither the Python services (`work-scope-rag`, `auth-service`) nor `dashboard` are touched.

**Primary Dependencies**: `laravel/framework ^13`, phpunit (framework default). No new packages, no Livewire, no Vite. Outbound HTTP via Laravel's built-in `Http` client, unchanged.

**Storage**: None — the service persists no business state and remains a stateless HTTP consumer. No new database, queue, or cache backend.

**Testing**: PHPUnit — keep the existing suite green. Feature test `WidgetPageTest` → renamed `TestConsolePageTest` (asserts the relabeled heading "Inquiry Handler — Test Console" plus the existing required/optional field assertions); `ServiceTokenTest` test username `svc-widget` → `svc-handler`. Behavioral coverage unchanged; a rename-sweep assertion is added to prove SC-001 in the suite.

**Target Platform**: Linux, Docker Compose. Service renamed `inquiry-widget` → `inquiry-handler` in compose (service key, `container_name`, build `context`, `env_file` path), port `8003` unchanged.

**Project Type**: API-first web service with a test-console page (web application; server-rendered single page + one JSON endpoint).

**Performance Goals**: Unchanged — a disposition in a user-perceived window under 60 s (spec SC-002/SC-003); the rename introduces no new latency.

**Constraints**: HTTP-only between services (constitution I); no DB access; API contract frozen (FR-002); triage behavior unchanged (FR-005); hard cut to the new name with no alias (Clarifications); secrets remain env-only, never committed or logged.

**Scale/Scope**: Single small service; rename only. No behavioral, data, or interface changes.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Constitution Principle | Status | Notes |
|------------------------|--------|-------|
| I. Independently Deployable Services | PASS | Only the name changes. The service remains one independently deployable HTTP-only container; no shared imports, no cross-service DB access. |
| II. API-First, FastAPI, `/health` | PASS | The rename *strengthens* the API-first posture: the triage API is the primary supported interface and the page is demoted to a test console. `GET /health` unchanged. Existing Laravel deviation (UI/backend service in Laravel rather than FastAPI/Streamlit) is carried forward from 004, already justified by stakeholder request; recorded, not counted. |
| III. Human-in-the-Loop for Ambiguity (NON-NEGOTIABLE) | PASS | Triage rules untouched — escalate-by-default on ambiguity/failure is preserved exactly. |
| IV. Data Model Is the Source of Truth | PASS | No data changes; the service still owns no persistent state (shared Postgres stays with RAG/auth services). |
| V. Simplicity and Provisional Scope | PASS | Purely nominal rename — the smallest change that satisfies the spec. No new capability, no speculative work. |
| Tech: Docker Compose | PASS | Compose service/container renamed to `inquiry-handler`, port and health ordering unchanged. |
| Tech: Redis-backed task queue | PASS (not used) | Synchronous service; no background work; unchanged. |

### Post-Design Re-check (Gate: re-evaluated after Phase 1)

Re-checked against the Phase 1 design artifacts (data-model.md, contracts/, quickstart.md):

| Principle | Status | Post-design verification |
|-----------|--------|--------------------------|
| I. Independently Deployable Services | PASS | Source tree `inquiry-handler/` is self-contained; `contracts/inquiry-web.md` documents the renamed outward surface; outbound RAG/AI links unchanged. |
| II. API-First / `/health` | PASS | Contract re-published under the new identity with the frozen `POST /inquiry/triage` and `GET /health`; page documented as test console. |
| III. Human-in-the-Loop | PASS | Triage behavior untouched; escalate-by-default preserved. |
| IV. Data Model Source of Truth | PASS | data-model.md declares no new persistent data; transient DTOs unchanged. |
| V. Simplicity & Provisional Scope | PASS | Rename-only; no machinery added for compatibility (hard cut per Clarifications). |

No unresolved violations; the inherited deviations (Laravel UI stack; Z.AI/Groq LLM provider) are recorded below and are not introduced by this feature.

## Project Structure

### Documentation (this feature)

```text
specs/005-rename-inquiry-handler/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (rename decisions)
├── data-model.md        # Phase 1 output (no data changes; identity/glossary)
├── quickstart.md        # Phase 1 output (rename validation scenarios)
├── contracts/           # Phase 1 output
│   └── inquiry-web.md   # Inquiry Handler outward HTTP contract (frozen API + test console)
└── tasks.md             # Phase 2 output (/speckit.tasks command)
```

### Source Code (repository root)

```text
inquiry-handler/                 # renamed from inquiry-widget/ (directory move)
├── Dockerfile                   # unchanged
├── docker-entrypoint.sh         # comment: "Inquiry widget" → "Inquiry Handler"
├── .env.example                 # APP_NAME="Inquiry Handler"; comment updates
├── composer.json                # name sales-ops/inquiry-handler; description/keywords drop "widget"
├── composer.lock                # regenerated (package metadata only)
├── app/
│   ├── Http/Controllers/
│   │   ├── HealthController.php      # unchanged
│   │   └── InquiryController.php     # docblock: public triage API + test-console page
│   ├── Services/
│   │   ├── AuthApiClient.php         # comment: "the widget" → "the handler"
│   │   ├── RagApiClient.php          # unchanged
│   │   └── AiCallingService.php      # unchanged (comment tidy only)
│   ├── Triage/
│   │   ├── MessageExtractor.php      # comment: widget shape → test-console / handler framing
│   │   ├── PromptBuilder.php         # unchanged
│   │   ├── Dispatcher.php            # unchanged
│   │   └── Handlers/                 # unchanged (4 handler files)
│   └── Support/ServiceToken.php      # unchanged
├── bootstrap/app.php            # comment: "the widget endpoint" → "the triage API"
├── config/services.php          # comment: "This widget" → "This handler"
├── routes/web.php               # comments + route name 'widget' → 'inquiry.test-console'
├── resources/views/
│   ├── layouts/app.blade.php    # title/window label driven by APP_NAME (Inquiry Handler)
│   └── inquiry/index.blade.php  # page heading → "Inquiry Handler — Test Console"; form unchanged
├── public/                      # unchanged
└── tests/
    ├── Feature/
    │   ├── WidgetPageTest.php → TestConsolePageTest.php   # asserts relabeled heading + fields (SC-003)
    │   ├── InquiryTriageTest.php    # unchanged
    │   └── FailurePathsTest.php     # unchanged
    └── Unit/                      # unchanged except ServiceTokenTest svc-widget → svc-handler

docker-compose.yml                # service key, container_name, build context, env_file → inquiry-handler
```

**Structure Decision**: A straight rename of the existing 004 service — no new directories, no re-organization. The `inquiry-handler/` tree mirrors `inquiry-widget/` exactly, with identity-bearing files (composer metadata, `APP_NAME`, view label, route name, README, comments, tests asserting naming) updated and behavior-bearing files left untouched. This is the smallest change satisfying FR-001…FR-008 (constitution V).

## Complexity Tracking

> Constitution Check carries two inherited deviations from feature 004. Neither is introduced by this rename; this table records them for the record.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| (inherited) Backend/UI stack deviates from the constitutional default: **FastAPI/Streamlit → Laravel PHP** | The stakeholder explicitly requested a Laravel service for this triage capability (same rationale as `dashboard`) | Migrating to the constitutional default would rewrite a working service for no functional gain and contradict the stakeholder request |
| (inherited) LLM provider deviates from the constitutional default: **Anthropic → Z.AI (GLM)** | The stakeholder explicitly requested Z.AI hosting an open-source model, key via env var | Silently reverting to Anthropic would ignore the request; provider access stays isolated behind `AiCallingService` |

*(Structure Decision, Technical Context, and the above matrix form the complete /speckit.plan output for this feature. tasks.md is generated by /speckit.tasks.)*