<?php

namespace App\Services;

use App\Support\AiThroughputGuard;
use App\Triage\PromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Isolated caller for the AI provider (Groq serving the GPT-OSS open-weight
 * model family) — contract: ai-provider.md, research §3. The provider/model/key
 * are fully env-driven (FR-011) so the provider can be swapped by changing config.
 *
 * Returns a normalized struct Dispatcher can act on deterministically:
 * ['disposition' => ?string, 'reply' => ?string, 'reasoning' => ?string,
 *  'raw_ok' => bool]. Any failure yields disposition null → escalate-by-default.
 */
class AiCallingService
{
    /** Bounded retries for fast transient provider failures (429/5xx). */
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly PromptBuilder $promptBuilder,
        private readonly AiThroughputGuard $guard,
    ) {}

    /**
     * @deprecated Superseded by the scoring engine (feature 006). Factor
     *             services call the generic complete() instead; triage() is
     *             retained for reference and never invoked by the new flow.
     *
     * @param  array{result_count: int, results: array<mixed>}  $retrievedContext
     * @return array{disposition: string|null, reply: string|null, reasoning: string|null, raw_ok: bool}
     */
    public function triage(string $inquiry, array $retrievedContext): array
    {
        $key = (string) config('services.ai.key');

        if ($key === '') {
            return $this->failure('Missing AI_API_KEY configuration.');
        }

        $prompt = $this->promptBuilder->build($inquiry, $retrievedContext);

        // Not every AI-hosted model supports response_format; fall back to a
        // plain request on a 422 (provider-level validation) once (ai-provider.md).
        $job = ['system' => $prompt['system'] ?? '', 'user' => $prompt['user'] ?? ''];

        try {
            $response = $this->call($job, $key, withJsonMode: true);

            if ($this->needsPlainRetry($response)) {
                $response = $this->call($job, $key, withJsonMode: false);
            }
        } catch (ConnectionException) {
            // Request-level failure (DNS/connection/TCP timeout). Degrades to
            // escalate-by-default as with every other AI failure (FR-010).
            return $this->failure('AI provider unreachable (connection/timeout error).');
        }

        if ($response->failed()) {
            return $this->failure("AI provider HTTP {$response->status()}.");
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content)) {
            return $this->failure('AI provider returned an unexpected payload.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return $this->failure('AI output was not valid JSON.');
        }

        $disposition = $decoded['disposition'] ?? null;
        $reply = $decoded['reply'] ?? null;
        $reasoning = $decoded['reasoning'] ?? null;

        if (! is_string($disposition) || $disposition === '') {
            return $this->failure('AI output had a missing disposition.');
        }

        return [
            'disposition' => $disposition,
            'reply' => is_string($reply) ? $reply : '',
            'reasoning' => is_string($reasoning) ? $reasoning : '',
            'raw_ok' => true,
        ];
    }

    /**
     * Generic AI completion for factor services (feature 006 / research R5).
     *
     * Calls the provider in JSON mode with the 422→plain fallback, and returns
     * the decoded JSON array, or `null` on ANY failure (missing key, connection
     * error, HTTP status, unparseable/missing content). `null` feeds the
     * engine's drop-and-renormalize path (FR-007). Each factor owns its
     * system/user prompt and calls this method.
     *
     * `$model` selects the model id for this one call; when omitted the default
     * `services.ai.model` is used. Callers needing a stronger/weaker model for
     * a specific step (e.g. the research agent) pass it explicitly.
     *
     * @return array<mixed>|null
     */
    public function complete(string $system, string $user, ?string $model = null): ?array
    {
        // Feature 013 US2: respect the shared per-minute + in-flight budgets
        // before spending a call. Over budget (null) → fail this call open,
        // exactly as every other AI failure degrades.
        $token = $this->guard->start();

        if ($token === null) {
            return null;
        }

        try {
            return $this->completeGuarded($system, $user, $model);
        } finally {
            $this->guard->finish($token);
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function completeGuarded(string $system, string $user, ?string $model = null): ?array
    {
        $key = (string) config('services.ai.key');

        if ($key === '') {
            return null;
        }

        $job = ['system' => $system, 'user' => $user, 'model' => $model];

        $response = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->call($job, $key, withJsonMode: true);

                if ($this->needsPlainRetry($response)) {
                    $response = $this->call($job, $key, withJsonMode: false);
                }
            } catch (ConnectionException) {
                // A slammed/slow provider hangs rather than failing fast; do not
                // multiply a long wait by retrying timeouts — fail open.
                return null;
            }

            if ($response->successful()) {
                break;
            }

            // Only transparent rate-limit/overload responses retry; these come
            // back in milliseconds or seconds (TPM/RPM ceilings), so a short
            // backoff costs almost nothing while riding through the free tier's
            // transient 413 (token-per-minute) and 429 bursts.
            if (in_array($response->status(), [413, 429, 500, 502, 503, 504], true) && $attempt < self::MAX_RETRIES) {
                usleep(min(30, $this->retryAfterSeconds($response) ?: $attempt * 2) * 1_000_000);

                continue;
            }

            return null;
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content)) {
            return null;
        }

        return $this->extractJsonArray($content);
    }

    /**
     * Run several AI completions concurrently, resolving each job independently.
     *
     * Each job is `['key' => string, 'system' => string, 'user' => string,
     * 'model' => ?string]` (plus an optional `max_tokens` output cap). The whole
     * set is fired together with the number of in-flight requests bounded by
     * `services.ai.concurrency`. A 413 (token-per-minute)/429/5xx subset is
     * re-fired with backoff (honouring Groq's `Retry-After` header) across up to
     * `MAX_RETRIES` rounds; a 422 falls back to plain mode once. Any job that
     * still fails resolves to `null` (fail-open, FR-007).
     *
     * This is the parallel path for the research agent's layer-1 per-page notes:
     * independent batches no longer wait on each other.
     *
     * @param  array<int, array{key: string, system: string, user: string, model?: string|null, max_tokens?: int}>  $jobs
     * @return array<string, array<mixed>|null>
     */
    public function completeMany(array $jobs): array
    {
        $key = (string) config('services.ai.key');

        $pending = [];
        $results = [];

        foreach ($jobs as $job) {
            $jobKey = (string) ($job['key'] ?? '');

            if ($jobKey === '') {
                continue;
            }

            $pending[$jobKey] = $job;
            $results[$jobKey] = null;
        }

        if ($key === '' || $pending === []) {
            return $results;
        }

        // Feature 013 US2: reserve a shared (RPM + in-flight) slot per job
        // BEFORE the round fires, so an over-budget job fails open here
        // instead of spending a burst the provider would reject. Uncounted
        // calls (guard off/unavailable) still send without a token.
        $reservedTokens = [];

        foreach ($pending as $jobKey => $job) {
            $token = $this->guard->start();

            if ($token === null) {
                unset($pending[$jobKey]);

                continue;
            }

            if ($token !== '') {
                $reservedTokens[$jobKey] = $token;
            }
        }

        if ($pending === []) {
            return $results;
        }

        $concurrency = max(1, (int) config('services.ai.concurrency', 4));
        $timeout = (int) config('services.ai.timeout', 90);

        for ($attempt = 1; $pending !== [] && $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $responses = Http::pool(
                    function (Pool $pool) use ($pending, $key, $timeout) {
                        foreach ($pending as $jobKey => $job) {
                            $pool->as($jobKey)
                                ->withToken($key)
                                ->acceptJson()
                                ->asJson()
                                ->timeout($timeout)
                                ->post((string) config('services.ai.url'), $this->payload($job, true));
                        }
                    },
                    $concurrency,
                );
            } catch (Throwable) {
                break;
            }

            if (! is_array($responses)) {
                break;
            }

            $retry = [];
            $retryAfter = 0;

            foreach ($responses as $jobKey => $response) {
                // Pool response keys mirror the `as($key)` names; numeric-string
                // keys arrive as PHP ints, so normalise before matching against
                // `$pending` (also keyed by string-indexed array → ints).
                $jobKey = (string) $jobKey;

                if (! isset($pending[$jobKey])) {
                    continue;
                }

                $job = $pending[$jobKey];

                if ($response instanceof Response
                    && in_array($response->status(), [413, 429, 500, 502, 503, 504], true)
                    && $attempt < self::MAX_RETRIES) {
                    $retry[$jobKey] = $job;
                    $retryAfter = max($retryAfter, $this->retryAfterSeconds($response));

                    continue;
                }

                $resolved = $this->resolveResponse($job, $response);
                $results[$jobKey] = $resolved;
                $this->releaseReservation($reservedTokens, $jobKey);
            }

            if ($retry === []) {
                break;
            }

            // Token-per-minute ceilings free up on a ~60s window; wait at least
            // a couple of seconds (or the provider's Retry-After when present).
            usleep(max(2, min(30, $retryAfter > 0 ? $retryAfter : $attempt * 2)) * 1_000_000);
            $pending = $retry;
        }

        // Jobs still holding reservations when the rounds end (e.g. the pool
        // broke on a transport failure) must still release their slot.
        foreach ($reservedTokens as $token) {
            $this->guard->finish($token);
        }

        return $results;
    }

    /**
     * Release one job's reservation once it is resolved (no-op for tokens that
     * were never granted).
     *
     * @param  array<string, string>  $reservedTokens
     */
    private function releaseReservation(array &$reservedTokens, string $jobKey): void
    {
        if (! isset($reservedTokens[$jobKey])) {
            return;
        }

        $this->guard->finish($reservedTokens[$jobKey]);
        unset($reservedTokens[$jobKey]);
    }

    /**
     * Decode one pooled response for a job. A 422 is retried once in plain mode
     * (some providers/models reject response_format validation); anything else
     * that is not a successful, decodable JSON array resolves to `null`.
     *
     * @param  array<string, mixed>  $job
     * @return array<mixed>|null
     */
    private function resolveResponse(array $job, mixed $response): ?array
    {
        if (! $response instanceof Response) {
            return null;
        }

        if ($this->needsPlainRetry($response)) {
            try {
                $response = $this->call($job, (string) config('services.ai.key'), withJsonMode: false);
            } catch (ConnectionException) {
                return null;
            }
        }

        if ($response->successful()) {
            $content = $response->json('choices.0.message.content');

            if (is_string($content)) {
                return $this->extractJsonArray($content);
            }
        }

        return null;
    }

    /**
     * Whether a JSON-mode response needs retrying once in plain mode.
     *
     * Some provider/model pairs refuse `response_format=json_object` as a
     * validation-level error: Z.AI answered with 422 (the original signal this
     * fallback was written for), while Groq answers 400 with a body containing
     * `json_validate_failed` for gpt-oss-20b on accounts where JSON mode is
     * unsupported/unavailable. Plain mode still returns parseable JSON, so a
     * single retry recovers the call instead of failing the stage open.
     */
    private function needsPlainRetry(Response $response): bool
    {
        if ($response->status() === 422) {
            return true;
        }

        return $response->status() === 400
            && str_contains($response->body(), 'json_validate_failed');
    }

    /**
     * Seconds to pause for a rate-limited response, 0 when absent or unusable.
     */
    private function retryAfterSeconds(Response $response): int
    {
        $value = trim((string) $response->header('Retry-After'));

        if ($value === '' || ! ctype_digit($value)) {
            return 0;
        }

        return min((int) $value, 30);
    }

    /**
     * Decode the model's content as a JSON object, tolerating the free tier's
     * habit of wrapping/narrating around the JSON (Markdown fences, a line of
     * preamble or trailing text). Exact JSON is used as-is; otherwise the first
     * balanced top-level `{…}` object is excavated and decoded.
     *
     * @return array<mixed>|null
     */
    private function extractJsonArray(string $content): ?array
    {
        if (trim($content) !== '') {
            $decoded = json_decode(trim($content), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strpos($content, '{');

        while ($start !== false) {
            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($i = $start, $length = strlen($content); $i < $length; $i++) {
                $char = $content[$i];

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($char === '"') {
                    $inString = true;
                } elseif ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $candidate = substr($content, $start, $i - $start + 1);
                        $decoded = json_decode($candidate, true);

                        return is_array($decoded) ? $decoded : null;
                    }
                }
            }

            $start = strpos($content, '{', $start + 1);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $job  `['system' => string, 'user' => string, 'model' => ?string]`
     */
    private function call(array $job, string $key, bool $withJsonMode): Response
    {
        return Http::baseUrl('')
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.ai.timeout', 90))
            ->post((string) config('services.ai.url'), $this->payload($job, $withJsonMode));
    }

    /**
     * Chat-completions payload for one job: model, fixed temperature, an output
     * cap (gpt-oss defaults to 65K output tokens, and free-tier daily budgets
     * evaporate fast), and optional JSON-object response format. Jobs carrying
     * a `max_tokens` output cap (e.g. terse layer-1 notes) use it so a single
     * request stays under the free tier's token-per-minute ceiling.
     *
     * @param  array<string, mixed>  $job  `['system' => string, 'user' => string, 'model' => ?string, 'max_tokens' => ?int]`
     * @return array<string, mixed>
     */
    private function payload(array $job, bool $withJsonMode): array
    {
        $model = isset($job['model']) && is_string($job['model']) && $job['model'] !== ''
            ? $job['model']
            : (string) config('services.ai.model', 'openai/gpt-oss-20b');

        $maxOutputTokens = is_int($job['max_tokens'] ?? null) && $job['max_tokens'] > 0
            ? $job['max_tokens']
            : max(1, (int) config('services.ai.max_output_tokens', 4096));

        $payload = [
            'model' => $model,
            'temperature' => 0,
            'max_completion_tokens' => $maxOutputTokens,
            'messages' => [
                ['role' => 'system', 'content' => $job['system']],
                ['role' => 'user', 'content' => $job['user']],
            ],
        ];

        if ($withJsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * @return array{disposition: null, reply: null, reasoning: string, raw_ok: bool}
     */
    private function failure(string $reasoning): array
    {
        return [
            'disposition' => null,
            'reply' => null,
            'reasoning' => $reasoning,
            'raw_ok' => false,
        ];
    }
}
