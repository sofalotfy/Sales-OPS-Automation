# Sales Inbound Workflow Constitution

## Core Principles

### I. Independently Deployable Services
Every capability in the workflow (triage/RAG, enrichment, brief generation,
dashboard) is its own service with its own Dockerfile and can be built, run,
and redeployed without touching the others. Services communicate only over
HTTP within the Docker Compose network — no shared in-process imports across
service boundaries, no reaching into another service's database tables
directly.

### II. API-First, FastAPI by Default
Every backend service exposes its functionality through a documented FastAPI
HTTP API (auto docs via `/docs`). FastAPI is the default choice for new
services; a different stack is only justified when a specific service has a
concrete reason for it (e.g. the dashboard uses Streamlit because it's a UI,
not an API). Every service must expose a `/health` endpoint.

### III. Human-in-the-Loop for Ambiguity (NON-NEGOTIABLE)
Nothing in this workflow silently drops or auto-resolves an ambiguous case.
When the triage agent cannot confidently classify an inquiry, or enrichment
cannot find an industry mapping, the system must surface it to a human
(escalation record, Client Growth Director alert) rather than guessing or
discarding it. Automation augments the sales/CS team's judgment; it doesn't
replace it for judgment calls.

### IV. Data Model Is the Source of Truth
Structured state (leads, escalations, meetings, briefs, industry mappings)
lives in the shared Postgres database, not scattered across service memory
or buried only in HubSpot. HubSpot is the system of record for CRM-facing
data (contacts, deals, meetings) and is synced to/from, but internal
workflow state is owned by our own schema so the dashboard and any future
consumer has one place to read from.

### V. Simplicity and Provisional Scope (NON-NEGOTIABLE)
Sales team requirements have not yet been gathered — dashboard scope,
exact enrichment data sources, and rep-assignment logic are provisional
and expected to change. Build the smallest version that satisfies the
current spec, prefer stubs with clear `TODO`s over speculative
generalization, and treat anything not yet confirmed with the sales team
as subject to revision without ceremony.

## Technology Constraints

- Orchestration: Docker Compose (single `docker compose up` brings up the
  whole stack)
- Backend services: FastAPI (Python)
- Database: PostgreSQL with the `pgvector` extension (also backs the RAG
  vector store — no separate vector DB unless corpus scale later requires it)
- Background/async jobs: Redis-backed task queue
- Dashboard: Streamlit, reading only from the core API — never talks to
  other services or the database directly
- CRM integration: HubSpot API (leads, contacts, meetings)
- LLM provider: Anthropic (triage classification, enrichment/brief
  generation) — provider access isolated behind a thin wrapper so it can be
  swapped later

## Development Workflow

- This project follows Spec-Driven Development via Spec Kit: every feature
  goes through `/constitution` (this file) → `/specify` → `/clarify` →
  `/plan` → `/tasks` → `/implement` before code is written.
- Features are built one service at a time, starting with the RAG/triage
  system, since it has the fewest external dependencies (HubSpot, sales
  team input) blocking it.
- Any open question that depends on sales team input must be recorded in
  the spec's clarifications rather than assumed silently — mark it and move
  on with the best available default.

## Governance

This constitution supersedes ad hoc technical decisions made mid-implementation.
Given the provisional nature of several assumptions (marked above), amendments
are expected as real requirements surface — update this file explicitly when
they do, rather than letting practice silently drift from what's written here.
Complexity beyond what a principle allows must be justified in the relevant
spec or plan document.

**Version**: 0.1.0 | **Ratified**: 2026-09-05 | **Last Amended**: 2026-09-05