<?php

namespace App\Services;

use App\Support\AiThroughputGuard;
use App\Triage\PromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /**
     * Scheduling-round bound for completeMany(): each wave can wait at most
     * one token window, so this only guards against a pathological spin — the
     * real bound is `services.ai.guard_wait_seconds`.
     */
    private const MAX_SCHEDULING_ROUNDS = 300;

    /**
     * Job keys from the most recent completeMany() that never reached the
     * provider because the shared guard had no capacity inside the wait budget,
     * as opposed to jobs that were sent and came back unusable. A caller that
     * reports on the batch needs the difference: the first is a scheduling
     * skip, the second is a provider failure, and conflating them reports a
     * capacity problem as a broken model.
     *
     * @var list<string>
     */
    private array $lastNeverSent = [];

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
    public function complete(string $system, string $user, ?string $model = null, bool $jsonMode = true): array|string|null
    {
        $estimatedTokens = $this->estimatedTokens(['system' => $system, 'user' => $user]);
        $token = $this->guard->start($estimatedTokens);

        if ($token === null) {
            $token = $this->awaitGuardCapacity($estimatedTokens);

            if ($token === null) {
                Log::warning('AI guard budget is full; the analysis call failed open (not sent).', [
                    'tokens_est' => $estimatedTokens,
                    'token_cap' => config('services.ai.guard_max_tokens_per_min'),
                ]);

                return null;
            }
        }

        try {
            return $this->completeGuarded($system, $user, $model, $estimatedTokens, $token, $jsonMode);
        } finally {
            $this->guard->finish($token);
        }
    }

    /**
     * Bounded wait for a shared slot while the guard reports the budgets full.
     *
     * Polls the non-mutating probe (so waiting does not churn the budgets it is
     * waiting on) and reserves as soon as the call fits. Gives up when the
     * wait budget elapses, and immediately when the call's own size exceeds
     * the per-minute cap — that call could never fit, so waiting would only
     * burn the budget it needs later.
     */
    private function awaitGuardCapacity(int $estimatedTokens): ?string
    {
        $waitCap = max(0, (float) config('services.ai.guard_wait_seconds', 90));
        $tokenCap = max(1, (int) config('services.ai.guard_max_tokens_per_min', 7000));

        if ($waitCap <= 0 || $estimatedTokens >= $tokenCap) {
            return null;
        }

        $started = microtime(true);

        while (microtime(true) - $started < $waitCap) {
            usleep(1_000_000);

            if (! $this->guard->hasCapacity($estimatedTokens)) {
                continue;
            }

            $token = $this->guard->start($estimatedTokens);

            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @return array<mixed>|null
     */
    private function completeGuarded(
        string $system,
        string $user,
        ?string $model = null,
        int $estimatedTokens = 0,
        ?string $reservation = null,
        bool $jsonMode = true,
    ): array|string|null {
        $key = (string) config('services.ai.key');

        if ($key === '') {
            return null;
        }

        $job = ['system' => $system, 'user' => $user, 'model' => $model];

        $response = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->call($job, $key, withJsonMode: $jsonMode);

                if ($jsonMode && $this->needsPlainRetry($response)) {
                    $response = $this->call($job, $key, withJsonMode: false);
                }
            } catch (ConnectionException $e) {
                Log::warning('AI single completion aborted on a connection error (fail-open).', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);
                $this->guard->refund($estimatedTokens, $reservation);

                return null;
            }

            if ($response->successful()) {
                break;
            }

            $isDailyQuota = $this->isDailyTokenQuotaExhausted($response);

            if ($isDailyQuota) {
                Log::error('AI provider daily token quota (TPD) exhausted; analysis calls fail open until the quota resets.');
            }

            if (in_array($response->status(), [413, 429, 500, 502, 503, 504], true)
                || ($jsonMode && $this->isJsonValidationFailure($response))) {
                if ($attempt < self::MAX_RETRIES && ! $isDailyQuota) {
                    usleep($this->retryWaitSeconds($response, $attempt) * 1_000_000);

                    continue;
                }
            }

            $this->logSingleCompletionFailure($response, $model);

            if (! $this->isBillableResponse($response)) {
                $this->guard->refund($estimatedTokens, $reservation);
            }

            return null;
        }

        $content = $response?->json('choices.0.message.content');

        if (! is_string($content) || (! $jsonMode && trim($content) === '')) {
            $this->logSingleCompletionFailure($response, $model);

            return null;
        }

        return $jsonMode ? $this->extractJsonArray($content) : trim($content);
    }

    /**
     * Attribute a failed single completion instead of dropping it silently.
     */
    private function logSingleCompletionFailure(?Response $response, ?string $model): void
    {
        Log::warning('AI single completion failed (fail-open).', [
            'model' => $model,
            'status' => $response?->status(),
            'retry_after' => $response instanceof Response ? $this->retryAfterSeconds($response) : 0,
            'body' => $response instanceof Response ? mb_substr((string) $response->body(), 0, 200) : '',
        ]);
    }

    /**
     * Run several AI completions concurrently, resolving each job independently.
     *
     * Each job is `['key' => string, 'system' => string, 'user' => string,
     * 'model' => ?string]` (plus an optional `max_tokens` output cap). Jobs are
     * fired in *waves*: before every wave each pending job reserves a shared
     * slot (requests-per-minute, in-flight, and an estimated token-per-minute
     * budget charged as input chars/4 + output cap), and only the jobs that fit
     * the current window are sent, bounded by `services.ai.concurrency`. Jobs
     * that do not fit keep their place and ride the next ~60s window, so a
     * heavy fan-out paces itself to the provider's token budget instead of
     * being rejected (or dumped) as one oversized burst. A 413/429/5xx subset
     * is re-offered in a later wave (each job up to `MAX_RETRIES` attempts,
     * honouring `Retry-After`, and coasting to the next window on TPM
     * throttles); a 422 falls back to plain mode once. Jobs still unsent when
     * `guard_wait_seconds` elapses, or that exhaust their attempts, resolve to
     * `null` (fail-open, FR-007) and are logged.
     *
     * This is the parallel path for the research agent's layer-1 per-page notes:
     * independent batches no longer wait on each other.
     *
     * @param  array<int, array{key: string, system: string, user: string, model?: string|null, max_tokens?: int}>  $jobs
     * @return array<string, array<mixed>|string|null>
     */
    public function completeMany(array $jobs): array
    {
        $key = (string) config('services.ai.key');

        $pending = [];
        $results = [];
        $tokens = [];

        foreach ($jobs as $job) {
            $jobKey = (string) ($job['key'] ?? '');

            if ($jobKey === '') {
                continue;
            }

            $pending[$jobKey] = $job;
            $results[$jobKey] = null;
            $tokens[$jobKey] = $this->estimatedTokens($job);
        }

        if ($key === '' || $pending === []) {
            // Without a key nothing can be sent, so every job is a skip.
            $this->lastNeverSent = array_map(strval(...), array_keys($results));

            return $results;
        }

        // Feature 013 US2 + token pacing: the shared budgets (RPM, in-flight,
        // tokens-per-minute) gate each SEND WAVE, not just the initial scan.
        // Every wave reserves job-by-job right before the pool fires: whatever
        // fits the current token window goes out together and the rest keeps
        // its place for the next window. Reserving the whole batch up front
        // would let early bookings expire and then dump every job into one
        // minute — the exact 413/429 pile-up the token budget exists to stop.
        $reservedTokens = [];
        $refundable = [];
        $sent = [];
        $attempts = [];
        $lastResponse = [];
        $dailyQuota = false;

        $concurrency = max(1, (int) config('services.ai.concurrency', 4));
        $timeout = (int) config('services.ai.timeout', 90);
        $waitCap = max(0, (float) config('services.ai.guard_wait_seconds', 90));
        $schedulingStarted = microtime(true);

        for ($round = 1; $pending !== [] && $round <= self::MAX_SCHEDULING_ROUNDS; $round++) {
            if ($round > 1 && microtime(true) - $schedulingStarted >= $waitCap) {
                break;
            }

            $fire = [];
            $deferred = [];

            foreach ($pending as $jobKey => $job) {
                // A job that already burned its attempts is not re-offered.
                if (($attempts[$jobKey] ?? 0) >= self::MAX_RETRIES) {
                    continue;
                }

                $token = $this->guard->start($tokens[$jobKey] ?? 0);

                if ($token === null) {
                    $deferred[$jobKey] = $job;

                    continue;
                }

                if ($token !== '') {
                    $reservedTokens[$jobKey] = $token;

                    // Kept past `releaseReservation` so the final refund can
                    // name the token bucket this job was charged to.
                    $refundable[$jobKey] = $token;
                }

                $fire[$jobKey] = $job;
                $sent[$jobKey] = $job;
            }

            if ($deferred !== [] && $round === 1) {
                Log::info('AI guard budget is full; analysis jobs are deferred to the next window.', [
                    'jobs' => array_keys($deferred),
                    'rpm_cap' => config('services.ai.guard_max_per_min'),
                    'in_flight_cap' => config('services.ai.guard_max_inflight'),
                    'token_cap' => config('services.ai.guard_max_tokens_per_min'),
                ]);
            }

            if ($fire === []) {
                if ($deferred === []) {
                    break;
                }

                $remaining = $waitCap - (microtime(true) - $schedulingStarted);

                if ($remaining <= 0) {
                    break;
                }

                // Nothing fits the shared budgets right now. Poll cheaply when
                // the request budgets are fine (a slot frees in seconds),
                // otherwise coast to the next token window — never past the
                // remaining wait budget.
                $waitSeconds = $this->guard->hasCapacity()
                    ? 1
                    : min($this->secondsUntilNextWindow(), max(1, (int) ceil($remaining)));

                usleep($waitSeconds * 1_000_000);

                continue;
            }

            try {
                $responses = Http::pool(
                    function (Pool $pool) use ($fire, $key, $timeout, $tokens) {
                        foreach ($fire as $jobKey => $job) {
                            Log::info('AI request (pool batch)', [
                                'job' => $jobKey,
                                'model' => isset($job['model']) && is_string($job['model']) && $job['model'] !== ''
                                    ? $job['model']
                                    : (string) config('services.ai.model', 'openai/gpt-oss-20b'),
                                'json_mode' => ! ($job['text'] ?? false),
                                'max_tokens' => is_int($job['max_tokens'] ?? null) && $job['max_tokens'] > 0
                                    ? $job['max_tokens']
                                    : max(1, (int) config('services.ai.max_output_tokens', 4096)),
                                'tokens_est' => $tokens[$jobKey] ?? 0,
                                'user_chars' => mb_strlen((string) $job['user']),
                                'user_preview' => mb_substr((string) $job['user'], 0, 250),
                            ]);

                            $pool->as($jobKey)
                                ->withToken($key)
                                ->acceptJson()
                                ->asJson()
                                ->timeout($timeout)
                                ->post((string) config('services.ai.url'), $this->payload($job, ! ($job['text'] ?? false)));
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
            $tpmThrottled = false;

            foreach ($responses as $jobKey => $response) {
                // Pool response keys mirror the `as($key)` names; numeric-string
                // keys arrive as PHP ints, so normalise before matching against
                // `$fire` (also keyed by string-indexed array → ints).
                $jobKey = (string) $jobKey;

                if (! isset($fire[$jobKey])) {
                    continue;
                }

                $attempts[$jobKey] = ($attempts[$jobKey] ?? 0) + 1;

                $job = $fire[$jobKey];

                if ($response instanceof Response) {
                    Log::info('AI response (pool batch)', [
                        'job' => $jobKey,
                        'status' => $response->status(),
                        'body' => mb_substr((string) $response->body(), 0, 250),
                    ]);
                }

                $lastResponse[$jobKey] = $response instanceof Response
                    ? [
                        'status' => $response->status(),
                        'retry_after' => $this->retryAfterSeconds($response),
                        'body' => mb_substr((string) $response->body(), 0, 200),
                    ]
                    : ['status' => 'n/a', 'retry_after' => 0, 'body' => ''];

                $isDailyQuota = $response instanceof Response
                    && $this->isDailyTokenQuotaExhausted($response);

                if ($isDailyQuota) {
                    $dailyQuota = true;
                }

                if ($response instanceof Response
                    && (in_array($response->status(), [413, 429, 500, 502, 503, 504], true)
                        || (! ($job['text'] ?? false) && $this->isJsonValidationFailure($response)))
                    && ($attempts[$jobKey] ?? 0) < self::MAX_RETRIES
                    && ! $isDailyQuota) {
                    $retry[$jobKey] = $job;
                    $retryAfter = max($retryAfter, $this->retryAfterSeconds($response));

                    if ($this->isTokenPerMinuteThrottle($response)) {
                        $tpmThrottled = true;
                    }

                    // The wave is over for this job: give the in-flight slot
                    // back now so a re-fire (next window) can reserve one
                    // again, instead of parking a slot while it waits.
                    $this->releaseReservation($reservedTokens, $jobKey);

                    continue;
                }

                $resolved = $this->resolveResponse($job, $response);
                $results[$jobKey] = $resolved;
                $this->releaseReservation($reservedTokens, $jobKey);
            }

            // Deferred jobs keep their place; retried jobs re-enter the race
            // for the next window.
            $pending = $retry + $deferred;

            if ($pending === []) {
                break;
            }

            // Token-per-minute ceilings only reset on the ~60s window — sleeping
            // the provider's Retry-After and re-firing into the same saturated
            // minute just re-exhausts it. An explicit TPM throttle coasts to
            // the window; otherwise poll briefly (a token window frees sooner
            // than a full minute once the bookings drain).
            $waitSeconds = $tpmThrottled || ! $this->guard->hasCapacity()
                ? $this->secondsUntilNextWindow()
                : max(2, min(30, $retryAfter > 0 ? $retryAfter : 2));

            $remaining = $waitCap - (microtime(true) - $schedulingStarted);

            if ($remaining <= 0) {
                break;
            }

            usleep(min($waitSeconds, max(1, (int) ceil($remaining))) * 1_000_000);
        }

        $neverSent = array_values(array_diff(array_keys($pending), array_keys($sent)));

        $this->lastNeverSent = array_map(strval(...), $neverSent);

        if ($neverSent !== []) {
            Log::warning('AI guard budget stayed exhausted; analysis jobs failed open (never sent).', [
                'jobs' => $neverSent,
                'model' => $pending[$neverSent[0]]['model'] ?? null,
                'rpm_cap' => config('services.ai.guard_max_per_min'),
                'in_flight_cap' => config('services.ai.guard_max_inflight'),
                'token_cap' => config('services.ai.guard_max_tokens_per_min'),
            ]);
        }

        // Jobs still holding reservations when the rounds end (e.g. the pool
        // broke on a transport failure) must still release their slot.
        foreach ($reservedTokens as $token) {
            $this->guard->finish($token);
        }

        // A hard daily-quota block (Groq "tokens per day") is not time-boxed
        // like TPM — retrying it wastes the remains of the minute. Surface it
        // once so operators read the real cause instead of a silent drop.
        if ($dailyQuota) {
            Log::error('AI provider daily token quota (TPD) exhausted; analysis jobs fail open until the quota resets.');
        }

        // Jobs that were actually sent yet resolved to null (retry exhaustion,
        // provider rejection, or unparseable JSON) get logged so a "batch
        // failed" in research is attributable instead of silent. Lanes that
        // never cleared the guard were logged above and are not re-reported.
        $unresolved = [];

        foreach ($sent as $jobKey => $job) {
            if (($results[$jobKey] ?? null) !== null) {
                continue;
            }

            $unresolved[$jobKey] = $job;

            // Return the token booking unless the final attempt actually
            // consumed model tokens (2xx billed the completion; a 400
            // json_validate_failed still billed its input). A job that only
            // ever saw 429/413/5xx/socket failures refills the minute window.
            $final = $lastResponse[$jobKey] ?? null;
            $finalBilled = is_array($final)
                && ($final['status'] === 200
                    || ($final['status'] === 400
                        && is_string($final['body'] ?? null)
                        && str_contains($final['body'], 'json_validate_failed')));

            if (! $finalBilled) {
                $this->guard->refund($tokens[$jobKey] ?? 0, $refundable[$jobKey] ?? null);
            }
        }

        if ($unresolved !== []) {
            Log::warning('AI analysis jobs stayed unresolved after retries (fail-open).', [
                'jobs' => array_keys($unresolved),
                'model' => $unresolved[array_key_first($unresolved)]['model'] ?? null,
                'responses' => array_map(
                    fn (string $jobKey) => $lastResponse[$jobKey] ?? null,
                    array_keys($unresolved),
                ),
            ]);
        }

        return $results;
    }

    /**
     * Job keys the most recent completeMany() never sent to the provider.
     *
     * Callers use this to tell a capacity skip apart from a genuine failure:
     * these jobs made no HTTP request at all, so an empty result for them says
     * nothing about the model or the prompt.
     *
     * @return list<string>
     */
    public function lastNeverSentJobs(): array
    {
        return $this->lastNeverSent;
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
    private function resolveResponse(array $job, mixed $response): array|string|null
    {
        if (! $response instanceof Response) {
            return null;
        }

        $textMode = (bool) ($job['text'] ?? false);

        if (! $textMode && $this->needsPlainRetry($response)) {
            try {
                $response = $this->call($job, (string) config('services.ai.key'), withJsonMode: false);
            } catch (ConnectionException) {
                return null;
            }
        }

        if (! $response->successful()) {
            return null;
        }

        if ($textMode) {
            return $this->resolveText($response);
        }

        $content = $response->json('choices.0.message.content');

        return is_string($content) ? $this->extractJsonArray($content) : null;
    }

    /**
     * Text-mode decode for one response.
     *
     * A reasoning model (gpt-oss) spends `max_completion_tokens` on its thinking
     * before it writes the answer, so a completion that reasons past the budget
     * returns 200 with an empty `content` and `finish_reason: length`. That
     * reasoning is still useful prose, so it is used as the fallback rather than
     * discarding a call that was billed — and the truncation is logged, because
     * a "batch failed" whose provider dashboard shows only 200s is otherwise
     * unattributable.
     */
    private function resolveText(Response $response): ?string
    {
        $content = $response->json('choices.0.message.content');
        $content = is_string($content) ? trim($content) : '';

        if ($content !== '') {
            return $content;
        }

        $reasoning = $response->json('choices.0.message.reasoning')
            ?? $response->json('choices.0.message.reasoning_content');
        $reasoning = is_string($reasoning) ? trim($reasoning) : '';

        Log::warning('AI text completion returned no content; used its reasoning output instead.', [
            'finish_reason' => $response->json('choices.0.finish_reason'),
            'reasoning_chars' => mb_strlen($reasoning),
            'completion_tokens' => $response->json('usage.completion_tokens'),
        ]);

        return $reasoning !== '' ? $reasoning : null;
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
     * Whether a 400 is Groq's JSON-mode validation rejection. These arrive with
     * an empty `failed_generation` when the model produced no content at all on
     * that request (intermittent on the free tier), so a bounded re-fire next
     * round usually rides through it — the round loop treats it like a transient
     * overload instead of failing the stage open.
     */
    private function isJsonValidationFailure(Response $response): bool
    {
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
     * Backoff for one retry round. A token-per-minute throttle (413 or a 429
     * that mentions TPM) is not eased by sleeping Retry-After — the budget
     * only resets on the ~60s window — so wait until the window rolls over
     * instead of re-firing the whole batch into the same saturated minute.
     */
    private function retryWaitSeconds(Response $response, int $attempt): int
    {
        if ($this->isTokenPerMinuteThrottle($response)) {
            return $this->secondsUntilNextWindow();
        }

        return max(2, min(30, $this->retryAfterSeconds($response) ?: $attempt * 2));
    }

    /**
     * Whether a 413/429 response is the token-per-minute ceiling (as opposed
     * to a request-per-minute ceiling, a daily quota, or a too-large-prompt
     * 413). Only a body that explicitly names TPM rotates on the 60s window,
     * so only it deserves the window-aligned backoff; a bare 413 (prompt too
     * long) or a plain 429 rides the short Retry-After backoff.
     */
    private function isTokenPerMinuteThrottle(Response $response): bool
    {
        if (! in_array($response->status(), [413, 429], true)) {
            return false;
        }

        return preg_match('/tokens per minute|\(tpm\)|token-per-minute/i', (string) $response->body()) === 1;
    }

    /**
     * Whole seconds until the next 60s budget window opens (the TPM/RPM keys
     * rotate on `YmdHi`). Bounded so a rounding error never yields a no-op.
     */
    private function secondsUntilNextWindow(): int
    {
        return max(2, 61 - (int) now()->second);
    }

    /**
     * Whether a failure response still consumed provider tokens, so the guard
     * booking must be kept rather than refunded. A 2xx billed the completion;
     * a 400 json_validate_failed sent its input to the model before rejecting
     * the output.
     */
    private function isBillableResponse(?Response $response): bool
    {
        if (! $response instanceof Response) {
            return false;
        }

        return $response->status() === 200 || $this->isJsonValidationFailure($response);
    }

    /**
     * Guard token estimate for one job: rough input tokens (chars ÷ 4, system
     * + user, matching the 4 chars/token approximation providers use) plus the
     * output cap actually sent in the payload.
     *
     * @param  array<string, mixed>  $job
     */
    private function estimatedTokens(array $job): int
    {
        $inputChars = mb_strlen((string) ($job['system'] ?? ''))
            + mb_strlen((string) ($job['user'] ?? ''));

        $maxOutput = is_int($job['max_tokens'] ?? null) && $job['max_tokens'] > 0
            ? $job['max_tokens']
            : max(1, (int) config('services.ai.max_output_tokens', 2048));

        return max(1, (int) (($inputChars / 4) + $maxOutput));
    }

    /**
     * Whether a 429 is a hard daily quota block ("tokens per day"/TPD) rather
     * than the transient per-minute throttle. TPD only resets on the quota
     * window (usually midnight), so retrying it burns calls without recovery.
     */
    private function isDailyTokenQuotaExhausted(Response $response): bool
    {
        if ($response->status() !== 429) {
            return false;
        }

        return preg_match('/tokens per day|\(tpd\)|per day|daily( quota)?/i', (string) $response->body()) === 1;
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
        Log::info('AI request (single)', [
            'model' => isset($job['model']) && is_string($job['model']) && $job['model'] !== ''
                ? $job['model']
                : (string) config('services.ai.model', 'openai/gpt-oss-20b'),
            'json_mode' => $withJsonMode,
            'max_tokens' => is_int($job['max_tokens'] ?? null) && $job['max_tokens'] > 0
                ? $job['max_tokens']
                : max(1, (int) config('services.ai.max_output_tokens', 4096)),
            'tokens_est' => $this->estimatedTokens($job),
            'user_chars' => mb_strlen((string) $job['user']),
            'user_preview' => mb_substr((string) $job['user'], 0, 250),
        ]);

        $response = Http::baseUrl('')
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.ai.timeout', 90))
            ->post((string) config('services.ai.url'), $this->payload($job, $withJsonMode));

        Log::info('AI response (single)', [
            'model' => isset($job['model']) && is_string($job['model']) && $job['model'] !== ''
                ? $job['model']
                : (string) config('services.ai.model', 'openai/gpt-oss-20b'),
            'status' => $response->status(),
            'body' => mb_substr((string) $response->body(), 0, 250),
        ]);

        return $response;
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
