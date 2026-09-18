# Research: API User Authentication

**Date**: 2026-09-06
**Purpose**: Resolve technical unknowns surfaced during planning of the API user authentication feature (user model, login, header token authorization).
**Source Feature**: [spec.md](./spec.md)

Context: small internal system, a handful of human operators plus one or two machine consumers, single shared PostgreSQL server (constitution Gate IV), FastAPI services behind Docker Compose (Gate II). Clarified scope: administrator-only provisioning, 90-day token lifetime with revocation, access-control only (no per-user quotas/rate limits).

## 1. Deployment topology: where does the user model and token check live?

- **Decision**: New standalone `auth-service` FastAPI service owning the `users`/`tokens` tables in a new `auth` database on the shared Postgres server. The existing RAG service enforces protection on its public endpoints by calling `auth-service GET /auth/verify` (HTTP introspection) on every protected request.
- **Rationale**: Constitution Gate I forbids shared in-process imports across service boundaries and requires HTTP-only communication, so a central authority is the only non-duplicative way for the RAG service *and any future service* (enrichment, dashboard, brief generation) to reject unauthorized requests without each service re-implementing auth. Gate IV makes the user model state owned by the shared Postgres. A dedicated service matches the project's one-capability-per-service pattern.
- **Alternatives considered**: Embedding auth inside `work-scope-rag` (rejected — makes the RAG service a coupling point; every future service would duplicate credential/token logic, violating Gate I); a shared Python package for token validation (rejected — that *is* a shared in-process import across service boundaries).

## 2. Token design: opaque server-side tokens vs JWT

- **Decision**: Opaque tokens. On login, generate 32 random bytes (`secrets.token_urlsafe(48)`, ~256 bits of entropy), return the raw token to the caller once, and store only `SHA-256(token)` in the `tokens` table (looked up by exact hash). Sent in the `Authorization: Bearer <token>` header. Tokens valid 90 days; revocation and user disablement checked on every verification.
- **Rationale**: The spec's success criteria demand *immediate* effect on token revocation and on user disablement (SC-3), and opaque stateful tokens give that with a single authoritative lookup — exactly the property introspection-based schemes are chosen for. 90-day long-lived tokens are the *worst* profile for self-contained JWTs (a leaked JWT stays valid until expiry, and revocation more state is needed anyway — a denylist reintroduces the lookup). Opaque tokens also keep DB-dump exposure limited (raw tokens never stored; only fast SHA-256 of a high-entropy value, which is not reversible). OWASP/industry guidance: for strong-revocation internal auth, opaque reference tokens over JWT.
- **Alternatives considered**: Self-contained JWT with short lifetime (rejected — 90-day clarified lifetime plus required revocation/disable-on-next-request makes statelessness pointless); JWT + refresh-token hybrid (rejected under Gate V — complexity not warranted at this scale); opaque tokens with a Redis cache (rejected — caching would delay revocation, contradicting SC-3, and Redis is reserved for the async task queue).

## 3. Password hashing

- **Decision**: Argon2id via `argon2-cffi`, OWASP baseline parameters `m=19 MiB, t=2, p=1`. Store the encoded Argon2id string (salt embedded) in `users.password_hash`. Verify with the library's constant-time hash comparison.
- **Rationale**: OWASP Password Storage Cheat Sheet (2026) ranks Argon2id first for new systems; it is memory-hard (resists GPU/ASIC), side-channel resistant in the `id` variant, has no input-length cap, and saves per-hash salt/verifier metadata in the standard `$argon2id$...` string. `argon2-cffi` is the maintained, benchmarked Python binding. Rehash-on-login skeleton supports future cost-parameter increases.
- **Alternatives considered**: bcrypt (OWASP-classified legacy-only; 72-byte truncation footgun); stdlib `scrypt` (solid fallback, but Argon2id is equally available and strictly preferred); PBKDF2 (only for FIPS-140 regimes, not applicable).

## 4. Brute-force and login throttling (FR-7)

- **Decision**: Per-account *progressive throttling with exponential backoff* instead of a hard account lockout: on each failed login, increment `users.failed_attempts` and set `users.locked_until = now + base * 2^{attempts-1}` (e.g. 1s, 2s, 4s, … capped at 15 min). While `locked_until` is in the future, login is refused with a generic `401`/`429`. Counter resets on successful login. No per-IP limiter in v1.
- **Rationale**: Research consensus (OWASP Brute-Force/Bot guides, 2026) is that a *hard* account lockout is a denial-of-service weapon (an attacker knowing usernames can lock out the whole user base); the practical defense for brute force is a per-account throttle that grows the cost of each guess. Per-IP limiting is explicitly weak (NAT/proxy evasion, punishes shared offices) and out of scope for an internal service with few accounts. Exponential backoff stored server-side (in `users`, the shared Postgres) works even if the service ever runs multiple workers. Generic error messages (never "user not found" vs "wrong password") avoid username enumeration.
- **Alternatives considered**: Fixed account lockout after N failures (rejected — DoS-able); per-IP limiter (rejected — NAT/proxy weaknesses, not needed internally); Redis counter (rejected under Gate V — a DB column already provides shared state across workers; Redis stays scoped to the task queue).

## 5. Service-to-service verification pattern

- **Decision**: `auth-service GET /auth/verify` (Bearer introspection). The RAG service extracts the `Authorization` header and forwards it to `/auth/verify`; a valid token yields `200` with `user_id`, `username`, `role`; invalid/expired/revoked/disabled yields `401`. RAG fails *closed*: if the auth service is unreachable, protected endpoints return `503`. No caching of verification results.
- **Rationale**: Matches the opaque-token decision — validity is authoritative server-side state. No caching means disablement/revocation take effect on the very next request (SC-3), at the price of a local-network round trip that is negligible at this scale (sub-ms same-machine; tiny request volume). Fail-closed preserves the security property even when infrastructure partially degrades.
- **Alternatives considered**: Local JWT verification (rejected in #2); cached introspection (rejected — delays revocation); a shared verification library (rejected — Gate I).

## 6. Bootstrap of the first administrator

- **Decision**: On startup, if the `users` table is empty, seed one admin account from environment variables (`AUTH_INITIAL_ADMIN_USERNAME`, `AUTH_INITIAL_ADMIN_PASSWORD`); refuse to start if those are missing when no users exist. Password is hashed with Argon2id like any other.
- **Rationale**: With administrator-only provisioning, *something* must create the first admin; an env-seeded bootstrap avoids shipping an open registration endpoint while keeping provisioning human-controlled (constitution Gate III). Explicit startup failure (rather than a silent unusable state) surfaces misconfiguration.
- **Alternatives considered**: A standalone CLI/script run inside the container (rejected — adds an operational step and a surface to get wrong; env bootstrap is simpler and testable); a default hard-coded admin (rejected — insecure).

## Decision Log Summary

| Decision | Chosen | Key alternative rejected |
|----------|--------|--------------------------|
| Topology | Standalone `auth-service` + HTTP introspection | Auth embedded in RAG service |
| Token format | Opaque (32-byte random, SHA-256 at rest, Bearer header) | JWT / JWT+refresh hybrid |
| Password hashing | Argon2id, OWASP baseline (m=19MiB, t=2, p=1) | bcrypt, scrypt, PBKDF2 |
| Brute-force defense | Per-account exponential backoff stored on the user row | Hard lockout, per-IP limiter |
| Verification | Un-cached `/auth/verify` introspection, fail-closed | Local/cached verification |
| Admin bootstrap | Env-seeded first admin when no users exist | CLI script, open signup |