# dashboard

The operator UI for the Sales Ops project. This is where a human actually works
with the triage pipeline: watching inquiries land, reading why each one scored
what it did, tuning the factor weights that produced those scores, and managing
the document corpus the pipeline reasons over.

Laravel 13 + Livewire 4 + Tailwind 4. Served at `http://localhost:8002`.

## It is a pure HTTP consumer

The dashboard owns **no database**. `DB_CONNECTION` is empty in `.env`, and the
default Laravel scaffolding in `database/` is unused. Every piece of state it
shows lives in another service, and it reaches them the same way any other
client would — over HTTP, with a server-side session:

| Upstream           | Used for |
| ------------------ | -------- |
| `auth-service`     | Sign-in. The session holds the bearer token; the raw token never reaches the browser. |
| `work-scope-rag`   | Document list, upload, replace, delete, and download. |
| `inquiry-handler`  | The classification log, run statistics, factor weights, and industry sectors. |

That means there is no local schema to migrate and no cross-database
consistency to reason about. The trade is that an upstream outage degrades the
page rather than breaking it: each section renders its own error state instead
of failing the whole screen.

## Pages

| Route                | What it does |
| -------------------- | ------------ |
| **Dashboard**        | Run totals, the classification mix, signal quality, and recent runs — all scoped to a date range. |
| **Documents**        | Upload, list, preview, download, and delete the document corpus. Upload, replace, and delete are Livewire components. |
| **Inquiry classification** | The factor-weight editor, and the full classification log with status, classification, and date filters. Each run opens to a detail page with its reasoning, factor scores, and the context the pipeline saw. |
| **Industry sectors** | The sector taxonomy the `industry_sector` factor scores against. |

Two details worth knowing when reading the numbers:

- **The run row is a partition.** Runs / Succeeded / Disqualified / Failed /
  In flight add up to the total — every run lands on exactly one card. A
  disqualify verdict wins over the score however it was reached, whether it
  scored below the threshold or a gate declined it.
- **A score of 0 means "no signal", not "bad lead".** Zero-scoring runs are
  reported separately and excluded from the average, so a factor that simply
  found nothing cannot drag the reported average down and read as lead quality.

Filters are shared between the dashboard and the classification log and use the
same control, so the two pages can be read against each other.

## Authentication

Sign-in posts to `auth-service POST /auth/login` and the returned bearer token
is kept in a **server-side** session. The browser only ever holds the session
cookie. The `guest.upstream` and `auth.upstream` middleware translate the
upstream token state into Laravel's authenticated/unauthenticated state.

The dashboard uses Laravel's default `laravel_session` cookie. The inquiry
handler overrides `SESSION_COOKIE` to `inquiry_session` so both UIs can share
one public domain behind the gateway without clobbering each other's session.

## Configuration

The only values that matter are the three upstream URLs:

| Env var | Default | Description |
|---------|---------|-------------|
| `AUTH_API_URL` | `http://auth-service:8001` | Sign-in and token verification. |
| `RAG_API_URL` | `http://work-scope-rag:8000` | Document management. |
| `INQUIRY_HANDLER_URL` | `http://inquiry-handler:8003` | Classification log, weights, sectors. |

## Run & test

```sh
docker compose up --build -d
composer install
npm install && npm run build     # or: npm run dev
php artisan serve                 # local dev, defaults to :8000
php artisan test
```

`composer run dev` starts the PHP server and Vite together. The dashboard is
the one service with a frontend build step; if a change is not showing up in
the browser, that is the first thing to check.

## Design notes

- **No business logic here.** Scoring, classification, and the audit log belong
  to `inquiry-handler`. The dashboard reads and displays; changing a weight here
  is a request to that service, not a local edit.
- **Weights are the tuning surface, not the factor set.** Factors are
  code-registered in the inquiry handler. This UI can change how much a factor
  counts, never which factors exist.
- **Errors are per-section.** An unreachable upstream shows an inline message
  in the section it affects and leaves the rest of the page usable.
