# Data Model: API User Authentication

**Date**: 2026-09-06
**Source**: [spec.md](./spec.md) (Key Entities), [research.md](./research.md)

Tables live in the shared PostgreSQL server (constitution Gate IV) in a dedicated `auth` database, owned by the same `rag` role that runs the cluster. `auth-service` is the only service that reads/writes these tables (Gate I: services never reach into another service's tables).

## Entity: `users`

A person or internal system permitted to use the public API. Provisioned by an administrator only (clarified).

| Field | Type | Constraints / Notes |
|-------|------|---------------------|
| `id` | UUID | Primary key; server-generated. Public identifier returned to callers. |
| `username` | TEXT | Unique not-null. Login identifier. Case-sensitive; trimmed. |
| `password_hash` | TEXT | Argon2id encoded hash (`$argon2id$...`, salt embedded). Never the plaintext. |
| `role` | TEXT | `admin` \| `user` (CHECK). Admin accounts may manage users; `user` accounts only consume APIs. |
| `is_enabled` | BOOLEAN | Default `true`. Disabling takes effect immediately for existing tokens (FR-4/SC-3). |
| `failed_attempts` | INTEGER | Default `0`. Consecutive failed logins; reset on success. Drives backoff. |
| `locked_until` | TIMESTAMPTZ | Null = not throttled. While in the future, login attempts are refused. |
| `created_at` | TIMESTAMPTZ | Account creation time. |
| `updated_at` | TIMESTAMPTZ | Last modification time (status changes, password change, throttle updates). |

**Uniqueness**: `UNIQUE (username)` — duplicate login identifiers rejected (FR-1, edge case).

**Validation rules**:
- `username`: non-empty after trim; max 100 chars; `^[A-Za-z0-9_.-]+$` for consistency (machine and human identifiers).
- `password`: min 12 chars, max 1024; hashed with Argon2id (OWASP baseline `m=19MiB, t=2, p=1`, RFC 9106).
- `role` restricted to `admin`/`user` at the API layer and by CHECK constraint.

**Login throttling (FR-7, progressive backoff)**:
- On failed login: `failed_attempts += 1`; `locked_until = now + min(2^{attempts-1} s, 15 min)`.
- While `locked_until > now()`: refuse login with generic error; sanitize throttle state lightly.
- On successful login: reset `failed_attempts = 0`, `locked_until = NULL`.

## Entity: `tokens`

Proof of a successful login; a single opaque, server-side session credential.

| Field | Type | Constraints / Notes |
|-------|------|---------------------|
| `id` | UUID | Primary key; server-generated. |
| `user_id` | UUID | FK → `users.id`, ON DELETE CASCADE + indexed. |
| `token_hash` | TEXT | `SHA-256(raw_token)` hex — the only stored form (unique, indexed). Raw token shown once at login. |
| `expires_at` | TIMESTAMPTZ | Not null. Issued tokens valid 90 days (clarified FR-5). |
| `revoked_at` | TIMESTAMPTZ | Null while valid; set by logout or administrator revocation. |
| `created_at` | TIMESTAMPTZ | Issuance time. |
| `last_used_at` | TIMESTAMPTZ | Updated on each successful verification (identifies stale tokens for cleanup). |

**Uniqueness**: `UNIQUE (token_hash)` — exact-hash lookup, no prefix scan needed at this scale.

**Validity check (per request)**: a token is valid iff
- `token_hash` found, AND
- `revoked_at IS NULL`, AND
- `expires_at > now()`, AND
- the owning `users` row exists AND `is_enabled = true`.

Any failure → `401`, request not performed (FR-3, SC-2).

## Relationships

- `users` 1 ── N `tokens` (cascade delete on user removal).
- A verification resolves `token_hash → token → user`; the user's `role` is returned to the resource service for authorization (RAG treats any valid token as sufficient since it has a single user tier; `admin` is meaningful only on `auth-service`'s own endpoints).

## State Transitions

`users.is_enabled`:

```text
            admin (PATCH /auth/users/{id})
   enabled ───────────────────────────────► disabled
       ◄────────────────────────────────────────
        admin (PATCH /auth/users/{id})
```

Disabling inherits a hard guarantee: on the next verification request the owning token is refused regardless of `expires_at` (SC-3).

`tokens`:

```text
        login                 logout / admin revoke
   created ──────────► active ───────────────────────► revoked
                          │ 90-day expiry reached
                          ▼
                      (no row is deleted; lookup treats
                       expired_at as invalid)
```

## Concurrency

- Two login attempts for the same account update `failed_attempts`/`locked_until`; serialized by a row lock on `users` (single UPDATE statement with `now()` guard) so backoff cannot be raced.
- Concurrent logins from the same user each create an independent `tokens` row (FR/edge: revoking one does not affect the others).
- Verification reads do not block throttling writes; token rows are immutable except `revoked_at`/`last_used_at` so no read/write contention on the hot path.