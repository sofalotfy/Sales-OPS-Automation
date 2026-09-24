<?php

namespace Tests\Feature\Support;

use App\WebResearch\ResearchAgent;
use App\WebResearch\ResearchResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Http::fake() fixture factories shared by the feature tests.
 *
 * Each stub derives its URL from the same config the clients use, so fakes
 * intercept exactly the requests the handler makes without real DNS.
 */
class UpstreamStubs
{
    public static function authUrl(string $suffix): string
    {
        return trim((string) config('services.auth_api_url'), '/').$suffix;
    }

    public static function ragQueryUrl(): string
    {
        return trim((string) config('services.rag_api_url'), '/').'/query';
    }

    public static function aiUrl(): string
    {
        return (string) config('services.ai.url');
    }

    public static function bookingUrl(): string
    {
        return (string) config('services.booking_url');
    }

    /** A RAG query result shaped like the POST /query contract item. */
    public static function ragResult(
        int $rank = 1,
        string $text = 'Annual maintenance plans cover heating boiler servicing.',
        string $source = 'playbooks',
        string $title = 'Maintenance Plans',
    ): array {
        return [
            'rank' => $rank,
            'text' => $text,
            'similarity_score' => 0.72,
            'document_id' => 'doc-1',
            'source' => $source,
            'title' => $title,
            'chunk_index' => 4,
        ];
    }

    public static function token(string $token = 'service-token'): string
    {
        return $token;
    }

    public static function loginResponse(string $token = 'service-token'): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_at' => CarbonImmutable::now()->addMinutes(30)->toIso8601String(),
        ];
    }

    public static function fakeLoginOk(string $token = 'service-token'): void
    {
        Http::fake([
            self::authUrl('/auth/login') => Http::response(self::loginResponse($token), 200),
        ]);
    }

    public static function fakeLoginRejected(): void
    {
        Http::fake([
            self::authUrl('/auth/login') => Http::response(['detail' => 'Invalid credentials.'], 401),
        ]);
    }

    public static function fakeRagQuery(array $results = []): void
    {
        Http::fake([
            self::ragQueryUrl() => Http::response([
                'success' => true,
                'query' => 'inquiry',
                'result_count' => count($results),
                'results' => $results,
            ], 200),
        ]);
    }

    public static function fakeRagFailure(int $status = 503): void
    {
        Http::fake([
            self::ragQueryUrl() => Http::response(['detail' => 'Retrieval is unavailable.'], $status),
        ]);
    }

    /** First /query bounces 401 (token invalid), the retry after re-auth succeeds. */
    public static function fakeRag401ThenOk(array $results = []): void
    {
        $calls = 0;

        Http::fake(function (Request $request) use (&$calls, $results) {
            if ($request->url() === self::authUrl('/auth/login')) {
                return Http::response(self::loginResponse(), 200);
            }

            if ($request->url() !== self::ragQueryUrl()) {
                return null; // let other stubs (e.g. the AI provider) handle this request
            }

            $calls++;

            if ($calls === 1) {
                return Http::response(['detail' => 'Not authenticated.'], 401);
            }

            return Http::response([
                'success' => true,
                'query' => 'inquiry',
                'result_count' => count($results),
                'results' => $results,
            ], 200);
        });
    }

    public static function fakeAi(string $disposition = 'booking', ?string $reply = null, string $reasoning = 'meeting-ready intent'): void
    {
        $reply ??= 'Pick a time here: '.self::bookingUrl();

        Http::fake([
            self::aiUrl() => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'disposition' => $disposition,
                        'reply' => $reply,
                        'reasoning' => $reasoning,
                    ])],
                ]],
            ], 200),
        ]);
    }

    /** Raw content string to simulate non-JSON or malformed provider output. */
    public static function fakeAiJson(string $content): void
    {
        Http::fake([
            self::aiUrl() => Http::response([
                'choices' => [['message' => ['content' => $content]]],
            ], 200),
        ]);
    }

    /** Company-size factor (feature 009): the AI size estimate parsed back. */
    public static function fakeAiFactorScore(
        int $score,
        ?string $reasoning = null,
        ?string $sizeBand = null,
        ?int $employeeCount = null,
    ): void {
        $reasoning ??= $score === 0
            ? 'The research findings contain no reliable company-size signal.'
            : 'Estimated from the public research findings.';

        Http::fake([
            self::aiUrl() => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'score' => $score,
                    'size_band' => $sizeBand,
                    'employee_count' => $employeeCount,
                    'reasoning' => $reasoning,
                ])]]],
            ], 200),
        ]);
    }

    /** Scope gate (feature 007): the AI judges the inquiry in scope. */
    public static function fakeScopeAccept(string $reason = 'Within the served scope.'): void
    {
        self::fakeScopeResult(true, $reason);
    }

    /** Scope gate (feature 007): the AI judges the inquiry out of scope. */
    public static function fakeScopeDecline(string $reason = 'Out of the served scope.'): void
    {
        self::fakeScopeResult(false, $reason);
    }

    private static function fakeScopeResult(bool $inScope, string $reason): void
    {
        Http::fake([
            self::aiUrl() => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'in_scope' => $inScope,
                        'reason' => $reason,
                    ])],
                ]],
            ], 200),
        ]);
    }

    public static function fakeAiFailure(int $status = 500): void
    {
        Http::fake([
            self::aiUrl() => Http::response(['error' => ['message' => 'provider down']], $status),
        ]);
    }

    public static function authVerifyOk(): void
    {
        Http::fake([
            self::authUrl('/auth/verify') => Http::response([
                'user_id' => 1,
                'username' => 'admin',
                'role' => 'admin',
            ], 200),
        ]);
    }

    public static function authVerifyRejected(): void
    {
        Http::fake([
            self::authUrl('/auth/verify') => Http::response(
                ['detail' => 'Token is invalid or has expired.'],
                401,
            ),
        ]);
    }

    public static function fakeEverythingDown(): void
    {
        Http::fake([
            self::authUrl('/auth/login') => Http::response(self::loginResponse(), 200),
            self::ragQueryUrl() => Http::response(['detail' => 'unavailable'], 503),
            self::aiUrl() => Http::response(['error' => 'down'], 500),
        ]);
    }

    /**
     * A research agent stub for feature tests: returns a fixed result without
     * calling the AI or fetching pages. Bind it over ResearchAgent::class.
     */
    public static function researchAgent(ResearchResult $result): ResearchAgent
    {
        return new class($result) extends ResearchAgent
        {
            public function __construct(private ResearchResult $result) {}

            public function research(array $criteria, array $payload): ResearchResult
            {
                return $this->result;
            }
        };
    }
}
