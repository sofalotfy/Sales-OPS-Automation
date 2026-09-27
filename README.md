# Sales Ops Automation

An inbound-lead triage system for a B2B sales team. A prospective client fills
in an inquiry form; the system decides how well that request matches what the
company actually sells, and gives the sales team a scored, filterable log of
every inquiry that came in.

The judgement is deliberately **advisory**. Every inquiry is scored and
recorded, and every one stays visible to a human reviewer — nothing is
auto-answered, auto-routed, or dropped on a score alone.

```
                     ┌──────────────────────┐
   visitor ──────────▶  gateway (nginx)     │  one public entry point
                     └──────────┬───────────┘
                        /inquiry│
             ┌─────────────────┴──────────────────┐
             ▼                                    ▼
   ┌───────────────────┐              ┌────────────────────┐
   │  inquiry-handler  │─────────────▶│  work-scope-rag    │  document knowledge
   │  triage pipeline  │              └────────────────────┘
   └─────────┬─────────┘
             │  bearer token            ┌────────────────────┐
             ├─────────────────────────▶│  auth-service      │  users + tokens
             │                          └────────────────────┘
             │  read runs, tune weights
             ▼
   ┌───────────────────┐
   │     dashboard     │  operator UI
   └───────────────────┘

   postgres (pgvector)  ·  redis  ·  AI provider  ·  Tavily
```

## Services

| Service           | Stack                | Port  | What it does |
| ----------------- | -------------------- | ----- | ------------ |
| `gateway`         | nginx                | 8080  | Single public entry point. `/inquiry*` → inquiry-handler, everything else → dashboard. |
| `inquiry-handler` | Laravel (PHP 8.4)  | 8003  | The triage pipeline: intake API, web research, scope gate, weighted multi-factor scoring, classification log. |
| `work-scope-rag`  | FastAPI              | 8000  | Document ingestion and semantic search over company-scope knowledge. |
| `auth-service`    | FastAPI              | 8001  | User accounts, login, and token verification. The single source of identity. |
| `dashboard`       | Laravel + Livewire | 8002 | Operator UI: run log, document management, factor weights, industry sectors. |

Plus the shared infrastructure: `db` (postgres + pgvector), `redis` (queue
transport and the shared AI budget counters), and the `inquiry-worker` replicas
that actually run the pipeline.

Each service owns its data and exposes HTTP. The only cross-service coupling is
a URL in the environment, which keeps each one independently deployable and
locally testable.

## How an inquiry flows

1. A visitor submits the inquiry form (`POST /inquiry/triage`, authenticated
   with a shared `X-CRM-Key`). Contact fields are stored with the run but are
   **never sent to the AI**.
2. The run row is created and handed to the Redis queue; the caller gets `202`
   and an `inquiry_id` back immediately.
3. `inquiry-worker` picks it up and advances the row through the pipeline,
   writing each stage's columns as it lands:

   ```
   queued → processing → researching → scope_check → scoring → succeeded
                                                              ↘ failed
   ```

   - **Web research** looks the company and contact up and records what it found.
   - **Scope gate** asks whether the request is something the company actually
     does. A decline short-circuits to a `disqualify` verdict.
   - **Scoring** runs the registered factors, combines them as a weighted mean,
     and maps the result to `high | medium | low | disqualify`.
4. A per-classification reply is produced. A `high` verdict carries the booking
   link.
5. The operator watches it land on the dashboard's run log, and can retune the
   factor weights that produced the score.

Failures degrade rather than block: a factor that throws is dropped and the
remaining weights renormalize, a missing research key fails that stage open as
`indeterminate`, and the run still reaches a verdict.

## Design principles

- **Advisory, not autonomous.** A score is a reading aid for a human. Every
  inquiry is logged and reviewable; nothing is auto-decided on a lead's behalf.
- **The classification log is append-only.** Result fields are written exactly
  once, at completion. Stages write only their own columns before that, so the
  audit record is never half-overwritten.
- **A result is never fabricated.** If the pipeline cannot decide, the run
  lands `failed` with an error — it does not invent a verdict. On the intake
  side, a run row is deleted rather than left promising work that will never
  happen.
- **Degrade, don't drop.** Missing research, a failing factor, an unreadable
  weights store — each is recorded and worked around, so an inquiry is almost
  never lost to a dependency.
- **Idempotent intake.** The unique `(campaign_id, lead_id)` index means a
  retried submission re-acknowledges the existing run instead of re-running it
  and double-spending the AI budget.
- **Keys from the environment only.** No credential is ever committed, and
  fetched web content is treated as untrusted data that cannot issue
  instructions.

## Running it

Requires Docker with Compose.

```sh
cp inquiry-handler/.env.example inquiry-handler/.env
cp work-scope-rag/.env.example   work-scope-rag/.env
cp auth-service/.env.example     auth-service/.env
cp dashboard/.env.example        dashboard/.env

# Fill in AI_API_KEY, TAVILY_API_KEY, the dashboard's CRM_API_KEY, and the
# initial admin password, then:
docker compose up --build -d
```

| Surface        | URL |
| -------------- | --- |
| Dashboard      | http://localhost:8002 |
| Test console   | http://localhost:8003 |
| Gateway (both) | http://localhost:8080 |
| RAG API docs   | http://localhost:8000/docs |
| Auth API docs  | http://localhost:8001/docs |

The first RAG start downloads the embedding model, so give it a minute. Scale
the pipeline with:

```sh
docker compose up -d --scale inquiry-worker=3
```

Each service has its own README with the details:

- [`inquiry-handler/`](./inquiry-handler) — the pipeline, the API, and the factors
- [`work-scope-rag/`](./work-scope-rag) — document ingestion and search
- [`auth-service/`](./auth-service) — users and tokens
- [`dashboard/`](./dashboard) — the operator UI

## Tests

```sh
(cd inquiry-handler && composer install && php artisan test)
(cd dashboard       && composer install && php artisan test)

docker compose up -d db
docker compose run --rm work-scope-rag python -m pytest -q
docker compose run --rm auth-service   python -m pytest -q
```

The two Python services test against separate throwaway databases
(`rag_test`, `auth_test`) created by `db/init.sql`, so teardown never touches
live data. The Laravel services run on SQLite `:memory:` and never touch the
scoped Postgres store.

## Repository layout

```
.
├── auth-service/        FastAPI — users, login, token verification
├── dashboard/           Laravel + Livewire — operator UI
├── inquiry-handler/     Laravel — the triage pipeline and intake API
├── work-scope-rag/      FastAPI — document ingestion and semantic search
├── gateway/             nginx — single public entry point
├── db/                  postgres bootstrap (extensions, databases, pgvector)
├── docs/                smoke-test notes
├── specs/               feature specs, contracts, and plans (001 → 013)
├── docker-compose.yml   the whole stack
└── render.yaml          Render deployment blueprint
```

`specs/` is the project's source of truth for *why* things are the way they are
— each numbered directory is one feature with its spec, plan, data model,
contracts, and task list. Read the relevant one before changing behaviour.
