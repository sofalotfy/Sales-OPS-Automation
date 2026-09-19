<?php

namespace Tests\Feature\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Http::fake() fixture factories shared by the feature tests.
 *
 * Each stub derives its URL from the same config the client classes use, so
 * fakes intercept exactly the requests the dashboard makes without real DNS.
 */
class UpstreamStubs
{
    public static function authUrl(string $suffix): string
    {
        return trim((string) config('services.auth_api_url'), '/').$suffix;
    }

    public static function ragUrl(string $suffix): string
    {
        return trim((string) config('services.rag_api_url'), '/').$suffix;
    }

    public static function inquiryUrl(string $suffix): string
    {
        return trim((string) config('services.inquiry_handler_api_url'), '/').$suffix;
    }

    /** A factor item shaped exactly like inquiry-handler's /admin/factor-settings list. */
    public static function factor(
        string $name = 'scope_relevance',
        float $weight = 0.5,
        string $source = 'stored',
    ): array {
        return ['name' => $name, 'weight' => $weight, 'source' => $source];
    }

    /** Inquiry-handler GET /admin/factor-settings response. */
    public static function fakeFactorSettings(array $factors = [], array $stored = []): void
    {
        Http::fake([
            self::inquiryUrl('/admin/factor-settings') => Http::response([
                'factors' => $factors,
                'stored' => $stored,
            ], 200),
        ]);
    }

    /** Inquiry-handler PUT /admin/factor-settings returns the saved weights. */
    public static function fakeFactorSettingsUpdate(array $factors): void
    {
        Http::fake([
            self::inquiryUrl('/admin/factor-settings') => Http::response(['factors' => $factors], 200),
        ]);
    }

    public static function fakeFactorSettingsUnavailable(): void
    {
        Http::fake([
            self::inquiryUrl('/admin/factor-settings') => Http::response(
                ['detail' => 'Settings store unavailable.'],
                503,
            ),
        ]);
    }

    public static function fakeFactorSettingsRejected(string $detail = 'Unknown factor: nope'): void
    {
        Http::fake([
            self::inquiryUrl('/admin/factor-settings') => Http::response(['detail' => $detail], 422),
        ]);
    }

    /** A classification-log item shaped like inquiry-handler's summary rows. */
    public static function classificationResult(
        int $id = 1,
        string $classification = 'low',
        float $score = 0.0,
        string $message = 'Do you offer annual maintenance contracts?',
        string $reasoning = 'No factors are registered; catalog is empty.',
        string $createdAt = '2026-09-14T10:00:00+00:00',
        ?string $webResearchOutcome = null,
    ): array {
        return [
            'id' => $id,
            'classification' => $classification,
            'final_score' => $score,
            'inquiry_message' => $message,
            'reasoning' => $reasoning,
            'web_research_outcome' => $webResearchOutcome,
            'created_at' => $createdAt,
        ];
    }

    /** Full per-run detail shaped like the classification-reporting detail endpoint. */
    public static function classificationDetail(
        int $id = 1,
        string $classification = 'medium',
        float $score = 72.0,
        string $message = 'Do you offer annual maintenance contracts?',
        string $reasoning = 'Matched scope.',
    ): array {
        return [
            'id' => $id,
            'inquiry_message' => $message,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'classification' => $classification,
            'final_score' => $score,
            'reasoning' => $reasoning,
            'factor_scores' => [
                'company_size' => [
                    'score' => 72,
                    'weight' => 1.0,
                    'reasoning' => 'Matched target company size.',
                ],
            ],
            'dropped_factors' => [],
            'retrieved_context' => [
                'result_count' => 1,
                'results' => [['rank' => 1, 'title' => 'faq', 'document_id' => 'doc-1', 'similarity_score' => 0.7, 'text' => 'Yes we do.']],
            ],
            'system_prompt' => "You are the sales triage assistant for Example Corp's served scope.\n\nSERVED SCOPE\nEnterprise automation tooling.",
            'scope_check_outcome' => 'accept',
            'scope_check_reason' => 'Within the served scope.',
            'web_research_outcome' => 'accept',
            'web_research_reason' => 'Web research completed.',
            'web_research' => [
                'criteria' => [
                    'company' => ['name' => 'Example Corp', 'country_region' => 'United Kingdom'],
                    'person' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'company_context' => 'Example Corp'],
                ],
                'findings' => [
                    'outcome' => 'completed',
                    'summary' => 'Example Corp is an enterprise fintech; Ada Lovelace leads engineering there.',
                    'sources' => [
                        ['title' => 'Example Corp — About', 'url' => 'https://example.com/about'],
                        ['title' => 'Ada Lovelace on LinkedIn', 'url' => 'https://linkedin.com/in/ada-lovelace'],
                    ],
                    'limitations' => null,
                    'uncertain' => false,
                ],
                'audit' => [
                    'counts' => ['obtained' => 3, 'kept' => 2, 'rejected' => 1],
                    'kept' => [
                        ['title' => 'Example Corp — About', 'url' => 'https://example.com/about'],
                        ['title' => 'Ada Lovelace on LinkedIn', 'url' => 'https://linkedin.com/in/ada-lovelace'],
                    ],
                    'rejected' => [
                        ['title' => '10 Best Companies to Work For', 'url' => 'https://aggregator.example/list'],
                    ],
                    'filter_outcome' => 'ok',
                    'filter_keep' => [0, 1],
                    'filter_reason' => 'Matches the target entity.',
                    'rescue_used' => false,
                ],
            ],
            'created_at' => '2026-09-14T10:00:00+00:00',
        ];
    }

    /** Inquiry-handler GET /admin/classification-results (list) response. */
    public static function fakeClassificationResults(array $items, ?int $total = null): void
    {
        Http::fake([
            self::inquiryUrl('/admin/classification-results*') => Http::response([
                'items' => $items,
                'total' => $total ?? count($items),
                'limit' => 20,
                'offset' => 0,
            ], 200),
        ]);
    }

    /** Inquiry-handler GET /admin/classification-results/{id} (detail) response. */
    public static function fakeClassificationResult(array $detail): void
    {
        Http::fake([
            self::inquiryUrl('/admin/classification-results/*') => Http::response($detail, 200),
        ]);
    }

    public static function fakeClassificationResultMissing(): void
    {
        Http::fake([
            self::inquiryUrl('/admin/classification-results/*') => Http::response(
                ['detail' => 'Classification result not found.'],
                404,
            ),
        ]);
    }

    public static function fakeClassificationsUnavailable(): void
    {
        Http::fake([
            self::inquiryUrl('/admin/classification-results*') => Http::response(
                ['detail' => 'Classification log unavailable.'],
                503,
            ),
        ]);
    }

    /** A document summary shaped exactly like a RAG /documents item. */
    public static function document(
        string $id = 'doc-1',
        string $title = 'Sales Playbook 2026',
        string $status = 'ready',
        ?string $source = 'playbooks',
        string $fileType = 'md',
        int $chunkCount = 24,
        ?string $error = null,
        bool $originalAvailable = true,
    ): array {
        $item = [
            'document_id' => $id,
            'title' => $title,
            'source' => $source,
            'file_type' => $fileType,
            'status' => $status,
            'chunk_count' => $chunkCount,
            'original_available' => $originalAvailable,
            'created_at' => '2026-09-05T10:00:00+00:00',
            'updated_at' => '2026-09-05T10:00:00+00:00',
        ];

        return $error === null ? $item : $item + ['error' => $error];
    }

    /** A RAG GET /documents/{id}/content payload (reconstructed text). */
    public static function documentContent(
        string $id = 'doc-1',
        string $title = 'Sales Playbook 2026',
        string $status = 'ready',
        string $content = 'This is the extracted content preview text.',
        int $charCount = 46,
        int $chunkCount = 24,
        bool $originalAvailable = true,
    ): array {
        return [
            'document_id' => $id,
            'title' => $title,
            'source' => 'playbooks',
            'file_type' => 'md',
            'status' => $status,
            'char_count' => $charCount,
            'chunk_count' => $chunkCount,
            'original_available' => $originalAvailable,
            'content' => $content,
        ];
    }

    public static function token(string $token = 'stub-token'): string
    {
        return $token;
    }

    public static function fakeLoginOk(string $token = 'stub-token'): void
    {
        Http::fake([
            self::authUrl('/auth/login') => Http::response([
                'access_token' => self::token($token),
                'token_type' => 'bearer',
                'expires_at' => CarbonImmutable::now()->addDays(90)->toIso8601String(),
            ], 201),
        ]);
    }

    public static function fakeLoginRejected(): void
    {
        Http::fake([
            self::authUrl('/auth/login') => Http::response(
                ['detail' => 'Invalid credentials.'],
                401,
            ),
        ]);
    }

    public static function fakeLogoutOk(): void
    {
        Http::fake([
            self::authUrl('/auth/logout') => Http::response(['revoked' => true], 200),
        ]);
    }

    public static function fakeBlankRag(): void
    {
        Http::fake([
            self::ragUrl('/documents*') => Http::response([
                'items' => [],
                'total' => 0,
                'limit' => 50,
                'offset' => 0,
            ], 200),
        ]);
    }

    public static function fakeDocumentList(array $items): void
    {
        Http::fake([
            self::ragUrl('/documents*') => Http::response([
                'items' => $items,
                'total' => count($items),
                'limit' => 50,
                'offset' => 0,
            ], 200),
        ]);
    }

    public static function fakeDocumentCreate(): void
    {
        Http::fake([
            self::ragUrl('/documents') => Http::response(self::document(), 201),
        ]);
    }

    public static function fakeDocument409(): void
    {
        Http::fake([
            self::ragUrl('/documents') => Http::response(
                ['detail' => 'A document with the same content already exists (document_id=x).'],
                409,
            ),
        ]);
    }

    public static function fakeDocumentUpdate(string $id = 'doc-1', string $title = 'Renamed'): void
    {
        Http::fake([
            self::ragUrl('/documents/'.$id) => Http::response(
                self::document(id: $id, title: $title),
                200,
            ),
        ]);
    }

    public static function fakeDocumentContent(string $id = 'doc-1'): void
    {
        Http::fake([
            self::ragUrl('/documents/'.$id.'/content') => Http::response(
                self::documentContent(id: $id),
                200,
            ),
        ]);
    }

    public static function fakeDocumentFile(
        string $id = 'doc-1',
        string $bytes = 'original-bytes',
        string $filename = 'manual.md',
    ): void {
        Http::fake([
            self::ragUrl('/documents/'.$id.'/download') => Http::response(
                $bytes,
                200,
                [
                    'Content-Type' => 'text/markdown',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ],
            ),
        ]);
    }

    /**
     * Dashboard home overview: three status-filtered list calls returning
     * their per-status totals, plus one unscoped call for recent documents.
     */
    public static function fakeDashboardTotals(
        int $ready,
        int $processing,
        int $failed,
        array $recentItems = [],
    ): void {
        $totals = ['ready' => $ready, 'processing' => $processing, 'failed' => $failed];

        Http::fake(function (Request $request) use ($totals, $recentItems) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $status = $query['status'] ?? null;

            if ($status !== null) {
                return Http::response(
                    ['items' => [], 'total' => $totals[$status] ?? 0, 'limit' => 1, 'offset' => 0],
                    200,
                );
            }

            return Http::response(
                ['items' => $recentItems, 'total' => count($recentItems), 'limit' => 8, 'offset' => 0],
                200,
            );
        });
    }

    public static function fakeDocumentDelete(string $id = 'doc-1'): void
    {
        Http::fake([
            self::ragUrl('/documents/'.$id) => Http::response(
                ['deleted' => true, 'document_id' => $id],
                200,
            ),
            // The table reloads the list right after a delete.
            self::ragUrl('/documents*') => Http::response([
                'items' => [],
                'total' => 0,
                'limit' => 50,
                'offset' => 0,
            ], 200),
        ]);
    }

    public static function fakeUpstreamUnauthorized(): void
    {
        Http::fake([
            self::ragUrl('/*') => Http::response(['detail' => 'Not authenticated.'], 401),
            self::inquiryUrl('/admin/factor-settings') => Http::response(['detail' => 'Not authenticated.'], 401),
            self::inquiryUrl('/admin/classification-results*') => Http::response(['detail' => 'Not authenticated.'], 401),
            self::authUrl('/auth/login') => Http::response(
                ['detail' => 'Invalid credentials.'],
                401,
            ),
        ]);
    }
}
