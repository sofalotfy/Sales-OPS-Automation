# Research: Rename Inquiry Widget to Inquiry Handler

**Date**: 2026-09-13
**Purpose**: Resolve the unknowns of a purely nominal rename of the 004 Laravel triage service (`inquiry-widget`) to **Inquiry Handler** (`inquiry-handler`). No new capability, no data, no interface change. Decisions here feed [plan.md](./plan.md) and the Phase 1 artifacts.

## 1. What exactly is renamed (completion bar definition)

- **Decision**: The completion bar is the spec's SC-001 set: user- and operator-facing reference surfaces — service announcement/name (compose service + container + package metadata), `APP_NAME`, page title/heading, README, env example comments, route registration comments/docblocks that describe the service, and test assertions on the page label. Internal code comments are tidied opportunistically (they mention "widget"); compiled view-cache files under `storage/framework/views/` and `vendor/` are **not** hand-edited (they regenerate/regenerate with `composer install`).
- **Rationale**: Spec FR-001 + Edge Case ("stale reference in low-visibility places … does not affect users; user- and operator-facing references are the bar"). Scope must stay bounded (constitution V).
- **Alternatives considered**: Rewriting every textual occurrence including compiled caches and `vendor/` — rejected (no user value; cache artifacts regenerate); leaving low-visibility comments — rejected for tidiness, but not gate-keeping.

## 2. How to perform the rename safely (mechanical approach)

- **Decision**: `git mv`/`mv` `inquiry-widget/` → `inquiry-handler/` preserving all files including the untracked local `.env` and `.gitignore`. Then update identity-bearing files in place; run `composer update --lock` to regenerate `composer.lock` package metadata and `vendor/composer/installed.php`; run `php artisan config:clear && php artisan view:clear && php artisan route:clear` (or rely on the entrypoint) so no stale cache references the old path.
- **Rationale**: The service is not a git repo at the workspace root, but the directory move is a simple filesystem rename; the `.env` must keep working after the move (it is referenced by compose `env_file`). Compose healthcheck ordering and the in-memory auth token cache are unaffected.
- **Alternatives considered**: Keeping the directory but changing only the announced name — rejected (inconsistent identity, confusing; spec assumption treats on-disk location as part of the name).

## 3. Naming the test console page

- **Decision**: Page copy: **"Inquiry Handler — Test Console"** for the visible heading/title (the API-first position: this page is for manual verification of `POST /inquiry/triage`, not a product surface). The form (message + optional name/email + submit + result area) is unchanged.
- **Rationale**: Clarifications Q1 opted to keep the page at the same address and relabel it. A heading that says "Test Console" makes the role unambiguous (FR-003, SC-3).
- **Alternatives considered**: Keeping the customer-facing ask ("Ask us about our services") — rejected, misrepresents the page's role; moving to a new path — rejected by the user (Q1: Option A).

## 4. Handling historical `specs/004-*` documents

- **Decision**: Leave `specs/004-ai-inquiry-triage/*` untouched as the archival record of that feature. This includes its `plan.md`, `quickstart.md`, `tasks.md`, and `contracts/*` which reference `inquiry-widget`. Go-forward operations, validation, and the outward contract are owned by `005` via its own `plan.md`, `quickstart.md`, and `contracts/inquiry-web.md`.
- **Rationale**: Explicit user decision ("no don't touch them"). Rewriting past feature records would falsify history and touch dozens of path references for no user value (constitution V).
- **Alternatives considered**: Updating all 004 references — rejected by the user; adding a deprecation banner to 004 docs — rejected by the user's instruction to leave them alone.

## 5. URL / contract stability

- **Decision**: `GET /`, `POST /inquiry/triage`, and `GET /health` keep their exact paths, request/response shapes, and status codes (FR-002, FR-006). The internal Laravel route **name** for `GET /` changes cosmetically from `widget` to `inquiry.test-console`; route names are not part of the outward contract.
- **Rationale**: A rename must not break consumers; the API is now the primary interface so stability is paramount. No external system or in-repo service references the old hostname (`inquiry-widget:8003`), enabling the user-confirmed hard cut (Clarifications Q2).
- **Alternatives considered**: Keeping route name `widget` — rejected (inconsistent identity, trivial to change); adding a transition alias for `inquiry-widget` — rejected by the user (hard cut, no alias).

## 6. Rename of test artifacts

- **Decision**: Rename `tests/Feature/WidgetPageTest.php` → `tests/Feature/TestConsolePageTest.php` (page now asserted by its test-console heading and unchanged form fields); change the service-account test username `svc-widget` → `svc-handler` in `tests/Unit/ServiceTokenTest.php`. All other test files remain unchanged.
- **Rationale**: Tests that assert naming surfaces must follow the rename (FR-008); behavior-only tests are untouched to prove zero behavioral drift (FR-005).
- **Alternatives considered**: Keeping file names and only changing asserted strings — rejected (test names are developer-facing identity; inconsistent with SC-001 spirit); adding new tests that duplicate existing coverage — rejected (no new behavior to cover).

## 7. Observability / log naming (deferred to plan)

- **Decision**: No dedicated observability work. Laravel process/container and any log lines carry the host/service identity automatically via the renamed process name and `APP_NAME`; no metric/log pipeline exists in this stateless service.
- **Rationale**: Low impact; the spec does not mandate observability changes (Kwargs spec SCs tested by quickstart).
- **Alternatives considered**: Adding structured log fields with the new service name — rejected as scope creep for a rename (deferred, constitution V).