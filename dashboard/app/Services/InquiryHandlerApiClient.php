<?php

namespace App\Services;

use App\Support\UpstreamSession;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client over inquiry-handler's admin factor-settings API
 * (contracts/factor-settings-admin.md).
 *
 * Requests are signed with the server-side session's auth-service bearer
 * token; inquiry-handler verifies that token against auth-service before
 * serving any admin endpoint (VerifyUpstreamToken). The dashboard never sees
 * or stores the inquiry-handler response credentials itself.
 */
class InquiryHandlerApiClient
{
    public function __construct(private readonly ?string $baseUrl = null)
    {
    }

    private function baseUrl(): string
    {
        return $this->baseUrl ?: (string) config('services.inquiry_handler_api_url');
    }

    /** Effective weight of every registered factor (GET /admin/factor-settings). */
    public function getFactorSettings(): Response
    {
        return $this->authenticatedRequest()->get('/admin/factor-settings');
    }

    /** Replace the stored weights for registered factor names (PUT /admin/factor-settings). */
    public function updateFactorWeights(array $weights): Response
    {
        return $this->authenticatedRequest()->put('/admin/factor-settings', ['weights' => $weights]);
    }

    /**
     * Newest-first page of the classification log (contracts/classification-reporting.md).
     *
     * @param  int  $limit  clamped to 1–50 upstream
     * @param  int  $offset  0-based
     */
    public function getClassificationResults(int $limit = 20, int $offset = 0): Response
    {
        return $this->authenticatedRequest()
            ->get('/admin/classification-results', ['limit' => $limit, 'offset' => $offset]);
    }

    /** Full payload of a single classification run (contracts/classification-reporting.md). */
    public function getClassificationResult(int $id): Response
    {
        return $this->authenticatedRequest()->get("/admin/classification-results/{$id}");
    }

    /** Every catalog sector (GET /admin/sectors, contracts/sectors-admin.md). */
    public function getIndustrySectors(): Response
    {
        return $this->authenticatedRequest()->get('/admin/sectors');
    }

    /** Create a sector (POST /admin/sectors). */
    public function createIndustrySector(string $name, int $rating): Response
    {
        return $this->authenticatedRequest()->post('/admin/sectors', [
            'name' => $name,
            'rating' => $rating,
        ]);
    }

    /** Update a sector's name/rating (PUT /admin/sectors/{id}). */
    public function updateIndustrySector(int $id, string $name, int $rating): Response
    {
        return $this->authenticatedRequest()->put("/admin/sectors/{$id}", [
            'name' => $name,
            'rating' => $rating,
        ]);
    }

    /** Hard-delete a sector (DELETE /admin/sectors/{id}). */
    public function deleteIndustrySector(int $id): Response
    {
        return $this->authenticatedRequest()->delete("/admin/sectors/{$id}");
    }

    private function authenticatedRequest(): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl())->acceptJson()->timeout(5);

        $token = UpstreamSession::token();
        if ($token !== null) {
            $request = $request->withToken($token);
        }

        return $request;
    }
}