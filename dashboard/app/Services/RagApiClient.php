<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client over work-scope-rag's document API.
 *
 * Every call attaches the caller-supplied bearer token from the session
 * (UpstreamSession) as `Authorization: Bearer`. Responses are not thrown on;
 * callers handle 401/409/422/503 distinctly per dashboard-web contract.
 */
class RagApiClient
{
    public function __construct(private readonly ?string $baseUrl = null)
    {
    }

    private function baseUrl(): string
    {
        return $this->baseUrl ?: (string) config('services.rag_api_url');
    }

    /**
     * GET /documents — list metadata without chunk text (document-api.md).
     *
     * @param  string|null  $source  optional exact source filter
     * @param  string|null  $status  optional status filter (processing|ready|failed)
     */
    public function listDocuments(
        string $token,
        ?string $source = null,
        ?string $status = null,
        int $limit = 50,
        int $offset = 0,
    ): Response {
        $params = ['limit' => $limit, 'offset' => $offset];
        if ($source !== null) {
            $params['source'] = $source;
        }
        if ($status !== null) {
            $params['status'] = $status;
        }

        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($token)
            ->timeout(10)
            ->get('/documents', $params);
    }

    /**
     * POST /documents — ingest a document (multipart: file + metadata JSON).
     */
    public function createDocument(
        string $token,
        string $filename,
        string $content,
        ?string $title = null,
        ?string $source = null,
    ): Response {
        $metadata = [];
        if ($title !== null) {
            $metadata['title'] = $title;
        }
        if ($source !== null) {
            $metadata['source'] = $source;
        }

        return Http::baseUrl($this->baseUrl())
            ->withToken($token)
            ->timeout(60)
            ->attach('file', $content, $filename)
            ->post('/documents', ['metadata' => $metadata === [] ? '{}' : json_encode($metadata)]);
    }

    /**
     * PATCH /documents/{id} — metadata-only edit (document-metadata-api.md).
     * $source === null sends an explicit null so the RAG service clears it.
     */
    public function updateMetadata(
        string $token,
        string $documentId,
        ?string $title = null,
        ?string $source = null,
    ): Response {
        $payload = [];
        if ($title !== null) {
            $payload['title'] = $title;
        }
        $payload['source'] = $source;

        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($token)
            ->timeout(10)
            ->patch("/documents/{$documentId}", $payload);
    }

    /**
     * GET /documents/{id}/content — reconstructed extracted text for preview.
     */
    public function getDocumentContent(string $token, string $documentId): Response
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($token)
            ->timeout(10)
            ->get("/documents/{$documentId}/content");
    }

    /**
     * GET /documents/{id}/download — the stored original upload.
     */
    public function getDocumentFile(string $token, string $documentId): Response
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($token)
            ->timeout(30)
            ->get("/documents/{$documentId}/download");
    }

    /**
     * DELETE /documents/{id} — remove a document and its chunks.
     */
    public function deleteDocument(string $token, string $documentId): Response
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($token)
            ->timeout(10)
            ->delete("/documents/{$documentId}");
    }
}