<?php

namespace App\Services;

use App\Triage\PromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Isolated caller for the AI provider (Z.AI hosting the GLM open-source
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

    public function __construct(private readonly PromptBuilder $promptBuilder)
    {
    }

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
        $key = (string) config('services.zai.key');

        if ($key === '') {
            return $this->failure('Missing ZAI_API_KEY configuration.');
        }

        $prompt = $this->promptBuilder->build($inquiry, $retrievedContext);

        // Not every Z.AI-hosted model supports response_format; fall back to a
        // plain request on a 422 (provider-level validation) once (ai-provider.md).
        try {
            $response = $this->call($prompt, $key, withJsonMode: true);

            if ($response->status() === 422) {
                $response = $this->call($prompt, $key, withJsonMode: false);
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
     * `services.zai.model` is used. Callers needing a stronger/weaker model for
     * a specific step (e.g. the research agent) pass it explicitly.
     *
     * @return array<mixed>|null
     */
    public function complete(string $system, string $user, ?string $model = null): ?array
    {
        $key = (string) config('services.zai.key');

        if ($key === '') {
            return null;
        }

        $prompt = ['system' => $system, 'user' => $user];

        $response = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->call($prompt, $key, withJsonMode: true, model: $model);

                if ($response->status() === 422) {
                    $response = $this->call($prompt, $key, withJsonMode: false, model: $model);
                }
            } catch (ConnectionException) {
                // A slammed/slow provider hangs rather than failing fast; do not
                // multiply a long wait by retrying timeouts — fail open.
                return null;
            }

            if ($response->successful()) {
                break;
            }

            // Only transparent-rate-limit/overload responses retry; these come
            // back in milliseconds, so a short backoff costs almost nothing
            // while riding through the free tier's transient 429 bursts.
            if (in_array($response->status(), [429, 500, 502, 503, 504], true) && $attempt < self::MAX_RETRIES) {
                usleep($attempt * 2_000_000);
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
     * @param  array{system: string, user: string}  $prompt
     */
    private function call(array $prompt, string $key, bool $withJsonMode, ?string $model = null): Response
    {
        $payload = [
            'model' => $model ?? (string) config('services.zai.model', 'glm-4.7-flash'),
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => $prompt['system']],
                ['role' => 'user', 'content' => $prompt['user']],
            ],
        ];

        if ($withJsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return Http::baseUrl('')
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.zai.timeout', 90))
            ->post((string) config('services.zai.url'), $payload);
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