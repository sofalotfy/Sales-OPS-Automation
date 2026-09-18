# RAG Document Service

## Overview

A retrieval-augmented generation (RAG) service that stores company scope documents and provides capabilities to ingest documents into a vector store and query the store to retrieve relevant context.

## Clarifications

### Session 2026-09-05

- Q: When a document is submitted for ingestion, should the requesting system wait inline for processing to finish, or should the request be handed off and confirmed later? → A: Synchronous — the call returns only after the document is fully processed and queryable.
- Q: What form should an ingested document take when submitted to the service? → A: Plain text plus PDF/Markdown files, parsed by the service.
- Q: How should a replacement update to an existing document be handled when submitted with the same identity? → A: Atomic replace — a single update call swaps old content for new while keeping the same document ID, with no window where the document is unqueryable.
- Q: What query controls should callers be able to set when retrieving relevant passages? → A: Configurable result count (within a cap) plus optional filtering by document metadata.
- Q: Roughly how large is the company-scope document corpus this service is expected to hold? → A: Small — tens to a few hundred documents.

## User Scenarios & Testing

### Primary User Scenarios

1. **Document Ingestion**: An internal system or administrator uploads a company scope document to the service. The document is processed, chunked, embedded, and stored in the vector store. The user receives confirmation of successful ingestion with a document identifier.

2. **Contextual Query**: An internal system or user sends a natural language query against the company scope knowledge base. The service returns the most relevant document passages ranked by similarity, enabling downstream systems to use this context for generation or decision-making.

3. **Document Management**: An administrator can list, update, or remove previously ingested documents to keep the knowledge base current. Updating a document replaces its content in place while keeping the same document ID.

### Acceptance Scenarios

- **Given** a valid document is submitted, **When** ingestion completes, **Then** the system responds with a document ID and the document is immediately queryable.
- **Given** documents exist in the store, **When** a query is submitted with a requested result count and metadata filters, **Then** the system returns up to that count of ranked results matching the filters, with similarity scores.
- **Given** a query with no close matches, **When** results are below a relevance threshold, **Then** the system returns an empty result set with a clear indication that no matches were found.
- **Given** a document ID that does not exist, **When** a deletion or update is requested, **Then** the system returns a not-found error.
- **Given** an invalid or empty document is submitted, **When** ingestion is attempted, **Then** the system rejects it with a validation error.

### Edge Cases

- Very large documents (above size limits) are rejected with a clear error.
- Duplicate documents (same content) are handled gracefully (rejected or versioned).
- Concurrent ingestion and querying do not degrade service availability.

## Functional Requirements

| ID   | Requirement | Priority |
|------|-------------|----------|
| FR-1 | Accept document ingestion requests containing text content and metadata. | Must |
| FR-2 | Process and chunk ingested documents into retrievable segments. | Must |
| FR-3 | Store document embeddings in a vector store for similarity search. | Must |
| FR-4 | Accept natural language queries and return ranked, relevant document passages. | Must |
| FR-5 | Return similarity scores alongside each retrieved passage. | Must |
| FR-6 | Support document deletion by document identifier. | Should |
| FR-7 | Support listing all stored documents with basic metadata. | Should |
| FR-8 | Validate document inputs (non-empty, within size limits) before ingestion. | Must |
| FR-9 | Reject queries that are empty or malformed. | Must |
| FR-10 | Service APIs are accessible to authorized internal systems. Authentication is handled at the infrastructure/gateway layer; the service trusts requests that reach it. | Must |
| FR-11 | Ingestion is synchronous: the request responds with the document ID only after the document is fully processed, embedded, and queryable. | Must |
| FR-12 | Accept documents as plain text, PDF, or Markdown files and extract their textual content for processing. | Must |
| FR-13 | Support updating an existing document by ID with an atomic replace: old content is swapped for new in a single operation with no queryable gap. | Must |
| FR-14 | Queries accept a requested result count (capped at a maximum) and optional metadata filters; the service honors both when returning results. | Must |

## Success Criteria

- Ingestion of a standard-length document completes and the document becomes queryable within a reasonable timeframe.
- Query results return the most relevant passages for at least 80% of known company-scope questions in test scenarios.
- The system handles at least 100 concurrent ingestion requests without failure.
- The system handles at least 500 concurrent queries without failure.
- Error responses are clear, actionable, and returned promptly for all invalid inputs.

## Key Entities

| Entity | Description |
|--------|-------------|
| **Document** | A raw ingested document with unique identifier, content, metadata (e.g., source, title, ingestion timestamp), and status. |
| **Document Chunk** | A processed segment of a document, with its own embedding vector, parent document reference, and positional metadata. |
| **Query** | A natural language search request containing the query text and optional controls: a requested result count (within a max cap) and metadata filters. |
| **Query Result** | A ranked passage returned from the vector store, including the chunk content, similarity score, and parent document reference. |

## Assumptions

- The service is consumed by internal systems (e.g., other microservices or orchestration layers), not directly by end users.
- Documents are text-based: plain text, PDF, or Markdown. Other binary or multimedia formats are out of scope.
- The vector store and embedding generation are handled internally by the service; the consumer does not need to manage them.
- The corpus is small, expected to hold tens to a few hundred documents; the existing project vector-store approach is sufficient without a separate vector infrastructure.
- A reasonable default chunk size and overlap strategy will be applied unless configured per document.
- The service is deployed as a standalone, network-accessible component within the company infrastructure.

## Out of Scope

- User-facing UI or conversational interface.
- Real-time document synchronization or webhook-based ingestion triggers.
- Fine-tuning or training of embedding models.
- Multi-tenant isolation (single-tenant assumed unless specified).
