<?php

namespace App\Services;

use App\Support\UpstreamSession;
use Illuminate\Http\Client\PendingRequest;
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
    public function __construct(private readonly ?string $baseUrl = null) {}

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
    /**
     * One page of the classification log.
     *
     * `status` and `classification` are optional exact-match filters; `from` and
     * `to` are inclusive `Y-m-d` calendar-day bounds. A null value is omitted
     * from the query string entirely so the upstream treats it as unfiltered
     * rather than as an empty match.
     */
    public function getClassificationResults(
        int $limit = 20,
        int $offset = 0,
        ?string $status = null,
        ?string $classification = null,
        ?string $from = null,
        ?string $to = null,
    ): Response {
        $query = ['limit' => $limit, 'offset' => $offset];

        if ($status !== null && $status !== '') {
            $query['status'] = $status;
        }

        if ($classification !== null && $classification !== '') {
            $query['classification'] = $classification;
        }

        if ($from !== null && $from !== '') {
            $query['from'] = $from;
        }

        if ($to !== null && $to !== '') {
            $query['to'] = $to;
        }

        return $this->authenticatedRequest()
            ->get('/admin/classification-results', $query);
    }

    /** Full payload of a single classification run (contracts/classification-reporting.md). */
    public function getClassificationResult(int $id): Response
    {
        return $this->authenticatedRequest()->get("/admin/classification-results/{$id}");
    }

    /**
     * Aggregates for the landing page (contracts/classification-reporting.md).
     *
     * Distinct from getClassificationResults(): that describes a single page and
     * caps at 50 rows, so status/classification counts taken from it would
     * silently under-report once the log outgrows a page.
     *
     * `from` and `to` scope the aggregates to the same inclusive calendar days
     * the list filter uses. The window is applied to the single query every
     * count is derived from, so the cards, the mix, and `total` all describe
     * the same period instead of a filtered list beside whole-log numbers.
     */
    public function getClassificationStats(?string $from = null, ?string $to = null): Response
    {
        $query = [];

        if ($from !== null && $from !== '') {
            $query['from'] = $from;
        }

        if ($to !== null && $to !== '') {
            $query['to'] = $to;
        }

        return $this->authenticatedRequest()->get('/admin/classification-results/stats', $query);
    }

    /** Every catalog sector (GET /admin/sectors, contracts/sectors-admin.md). */
    public function getIndustrySectors(): Response
    {
        return $this->authenticatedRequest()->get('/admin/sectors');
    }

    /** Create a sector (POST /admin/sectors). */
    public function createIndustrySector(string $name, int $rating, string $description): Response
    {
        return $this->authenticatedRequest()->post('/admin/sectors', [
            'name' => $name,
            'rating' => $rating,
            'description' => $description,
        ]);
    }

    /** Update a sector's name/rating/description (PUT /admin/sectors/{id}). */
    public function updateIndustrySector(int $id, string $name, int $rating, string $description): Response
    {
        return $this->authenticatedRequest()->put("/admin/sectors/{$id}", [
            'name' => $name,
            'rating' => $rating,
            'description' => $description,
        ]);
    }

    /** Hard-delete a sector (DELETE /admin/sectors/{id}). */
    public function deleteIndustrySector(int $id): Response
    {
        return $this->authenticatedRequest()->delete("/admin/sectors/{$id}");
    }

    private function authenticatedRequest(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl())->acceptJson()->timeout(5);

        $token = UpstreamSession::token();
        if ($token !== null) {
            $request = $request->withToken($token);
        }

        return $request;
    }
}
