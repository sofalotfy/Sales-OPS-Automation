# auth-service

The identity service for the Sales Ops project. It owns user accounts and
bearer tokens, and it is the single source of truth for "who is calling".

Every other service verifies against it rather than implementing its own auth:
the dashboard signs users in here and keeps the returned token in a server-side
session, and `inquiry-handler` calls `GET /auth/verify` to gate its admin
routes. Nothing else in the stack stores a password or a raw token.

FastAPI + async SQLAlchemy on Postgres. Served at `http://localhost:8001`.

## Tokens

- **Opaque, not JWT.** Login returns 32 random bytes, url-safe encoded. There is
  no signing key to leak and no claim-set to get wrong — the token is a lookup
  key and nothing more.
- **Stored hashed.** Only the SHA-256 of the token is persisted. A database dump
  yields nothing usable.
- **Revocable and expiring.** A token row carries `expires_at` and `revoked_at`;
  `last_used_at` records the most recent successful verification.
- **Shown once.** The raw token is returned by login and never again.

Passwords are hashed with Argon2id. The parameters are tuned for an
interactive login rather than a batch job (`time_cost=2`,
`memory_cost=19456`, `parallelism=1`).

## API

OpenAPI docs: `http://localhost:8001/docs`

| Method | Path                 | Auth      | Purpose |
| ------ | -------------------- | --------- | ------- |
| POST   | `/auth/login`        | —         | Exchange username + password for a bearer token. |
| GET    | `/auth/verify`       | bearer    | Resolve a token to its user. The call every other service makes. |
| POST   | `/auth/logout`       | bearer    | Revoke the calling token. |
| POST   | `/auth/users`        | admin     | Create a user (`admin` or `user`). |
| GET    | `/auth/users`        | admin     | List users. |
| PATCH  | `/auth/users/{id}`   | admin     | Enable or disable a user. |
| DELETE | `/auth/users/{id}`   | admin     | Delete a user; their tokens cascade. |
| GET    | `/health`            | —         | Liveness probe. |

### `POST /auth/login`

```json
{ "username": "admin", "password": "…" }
```

```json
{
  "access_token": "…",
  "token_type": "bearer",
  "expires_at": "2026-12-26T12:00:00Z"
}
```

Bad credentials return the same error whether the username or the password was
wrong, so the response cannot be used to enumerate accounts.

### `GET /auth/verify`

```json
{ "user_id": "…", "username": "admin", "role": "admin" }
```

## Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `DATABASE_URL` | `postgresql+psycopg://rag:rag@localhost:5432/auth` | Async psycopg3 SQLAlchemy URL. |
| `AUTH_INITIAL_ADMIN_USERNAME` | `admin` | Bootstrap admin, seeded only when the users table is empty. |
| `AUTH_INITIAL_ADMIN_PASSWORD` | — | Bootstrap admin password. **Never commit a real value.** |
| `AUTH_TOKEN_EXPIRY_SECONDS` | `7776000` (90 days) | Token lifetime. Set very small in dev to exercise expiry. |

The service **refuses to start** with an empty users table and no bootstrap
credentials — a misconfigured deploy fails loudly rather than coming up with
nobody able to log in.

## Run

```sh
docker compose up --build -d
curl -s http://localhost:8001/health   # -> {"status":"ok"}
```

Postgres runs in the `db` container; the `auth` database and its tables are
created on startup.

## Tests

The suite runs in the container against the separate `auth_test` database
(created by `db/init.sql`), so teardown never touches live data.

```sh
docker compose up -d db                    # Postgres must be healthy
docker compose run --rm \
  -v "$PWD/auth-service":/authsrc:z \
  -e DATABASE_URL=postgresql+psycopg://rag:rag@db:5432/auth_test \
  -w /authsrc \
  auth-service python -m pytest -q
```
