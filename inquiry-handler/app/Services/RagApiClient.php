<?php

namespace App\Services;

use App\Support\ServiceToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client over work-scope-rag's retrieval endpoint (contract:
 * rag-query.md — existing POST /query, unchanged by this feature).
 *
 * The bearer token comes from the server-side ServiceToken cache and never
 * leaves the request cycle. On a 401 the token is invalidated and a SINGLE
 * retry happens; a second 401 is returned to the caller (→ escalate path).
 */
class RagApiClient
{
    public function __construct(
        private readonly ServiceToken $tokens,
        private readonly ?string $baseUrl = null,
    ) {
    }

    private function baseUrl(): string
    {
        return $this->baseUrl ?: (string) config('services.rag_api_url');
    }

    /**
     * POST /query — retrieve the most relevant chunks for a canonical message.
     * Responses are never thrown on; the triage flow inspects status + body.
     */
    public function query(string $query, int $topK = 5): Response
    {
        $response = $this->call(['query' => $query, 'top_k' => $topK]);

        if ($response->status() === 401) {
            $this->tokens->invalidate();

            return $this->call(['query' => $query, 'top_k' => $topK]);
        }

        return $response;
    }

    private function call(array $body): Response
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->withToken((string) $this->tokens->token())
            ->timeout(10)
            ->post('/query', $body);
    }
}