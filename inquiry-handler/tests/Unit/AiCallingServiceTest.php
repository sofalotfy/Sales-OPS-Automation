<?php

namespace Tests\Unit;

use App\Services\AiCallingService;
use App\Triage\PromptBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AiCallingService transport tests (feature 010 / FR-011):
 * - complete(): transient provider overload rides through a bounded retry; hard
 *   failures and missing keys still fail open to `null`; a model override lands
 *   in the request body.
 * - completeMany(): the research agent's layer-1 notes batches run concurrently
 *   and resolve independently — one slow/failed batch never blocks another.
 */
class AiCallingServiceTest extends TestCase
{
    private AiCallingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai.key' => 'test-key']);
        config(['services.ai.url' => 'https://ai.test/chat/completions']);
        config(['services.ai.model' => 'openai/gpt-oss-20b']);
        config(['services.ai.timeout' => 5]);
        $this->service = new AiCallingService(new PromptBuilder('scope'));
    }

    public function test_transient_overload_is_retried_then_succeeds(): void
    {
        Http::fake([
            'ai.test/*' => Http::sequence()
                ->push(['error' => ['code' => '1305']], 429)
                ->push(['error' => ['code' => '1302']], 429)
                ->push(['choices' => [['message' => ['content' => '{"ok":true}']]]]),
        ]);

        $result = $this->service->complete('Sys', 'User');

        $this->assertSame(['ok' => true], $result);
        Http::assertSentCount(3);
    }

    public function test_persistent_failure_still_fails_open_after_retries(): void
    {
        Http::fake([
            'ai.test/*' => Http::response(['error' => ['code' => '1305']], 429),
        ]);

        $this->assertNull($this->service->complete('Sys', 'User'));
        Http::assertSentCount(3);
    }

    public function test_hard_error_status_is_not_retried(): void
    {
        Http::fake([
            'ai.test/*' => Http::response(['error' => ['message' => 'Bad request.']], 400),
        ]);

        $this->assertNull($this->service->complete('Sys', 'User'));
        Http::assertSentCount(1);
    }

    public function test_missing_key_fails_open_without_any_request(): void
    {
        config(['services.ai.key' => '']);
        Http::fake();

        $this->assertNull($this->service->complete('Sys', 'User'));
        Http::assertNothingSent();
    }

    public function test_model_override_reaches_the_request_body(): void
    {
        Http::fake([
            'ai.test/*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]], 200),
        ]);

        $this->service->complete('Sys', 'User', model: 'openai/gpt-oss-120b');

        Http::assertSent(fn ($request) => $request['model'] === 'openai/gpt-oss-120b');
    }

    public function test_requests_carry_an_output_token_cap(): void
    {
        Http::fake([
            'ai.test/*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]], 200),
        ]);

        $this->service->complete('Sys', 'User');

        Http::assertSent(fn ($request) => ($request['max_completion_tokens'] ?? 0) >= 1);
    }

    public function test_markdown_wrapped_json_is_extracted(): void
    {
        Http::fake([
            'ai.test/*' => Http::response([
                'choices' => [['message' => ['content' => "Here you go:\n```json\n{\"outcome\":\"ok\",\"keep\":[1]}\n```"]]],
            ], 200),
        ]);

        $this->assertSame(['outcome' => 'ok', 'keep' => [1]], $this->service->complete('Sys', 'User'));
    }

    public function test_narrated_json_is_excavated(): void
    {
        Http::fake([
            'ai.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'Based on the candidates, this is my answer {"summary":"x","sources":[]} kind regards']]],
            ], 200),
        ]);

        $this->assertSame(['summary' => 'x', 'sources' => []], $this->service->complete('Sys', 'User'));
    }

    public function test_no_json_in_content_fails_open(): void
    {
        Http::fake([
            'ai.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'I cannot answer that right now.']]],
            ], 200),
        ]);

        $this->assertNull($this->service->complete('Sys', 'User'));
    }

    public function test_complete_many_resolves_each_job_independently(): void
    {
        Http::fake(function (Request $request) {
            $user = $request['messages'][1]['content'];

            return Http::response([
                'choices' => [['message' => ['content' => $user === 'Prompt A' ? '{"a":1}' : '{"b":2}']]],
            ], 200);
        });

        $result = $this->service->completeMany([
            ['key' => 'batch-0', 'system' => 'S', 'user' => 'Prompt A'],
            ['key' => 'batch-1', 'system' => 'S', 'user' => 'Prompt B'],
        ]);

        $this->assertSame(['batch-0' => ['a' => 1], 'batch-1' => ['b' => 2]], $result);
        Http::assertSentCount(2);
    }

    public function test_complete_retries_a_413_token_limit_burst(): void
    {
        Http::fake([
            'ai.test/*' => Http::sequence()
                ->push(['error' => ['message' => 'Request too large']], 413)
                ->push(['choices' => [['message' => ['content' => '{"ok":true}']]]], 200),
        ]);

        $this->assertSame(['ok' => true], $this->service->complete('Sys', 'User'));
        Http::assertSentCount(2);
    }

    public function test_complete_remaining_missing_key_after_max_retries_fails_open(): void
    {
        Http::fake([
            'ai.test/*' => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 413)
                ->push(['error' => ['message' => 'still overloaded']], 413)
                ->push(['error' => ['message' => 'still overloaded']], 413),
        ]);

        $this->assertNull($this->service->complete('Sys', 'User'));
        Http::assertSentCount(3);
    }

    public function test_complete_many_honours_a_per_job_output_cap(): void
    {
        Http::fake(function (Request $request) {
            return Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]], 200);
        });

        $this->service->completeMany([
            ['key' => 'notes', 'system' => 'S', 'user' => 'U', 'max_tokens' => 1024],
        ]);

        Http::assertSent(fn (Request $request) => ($request['max_completion_tokens'] ?? 0) === 1024);
    }

    public function test_complete_many_resolves_numeric_pool_keys(): void
    {
        Http::fake(function (Request $request) {
            return Http::response(['choices' => [['message' => ['content' => '{"note":"ok"}']]]], 200);
        });

        $result = $this->service->completeMany([
            ['key' => '0', 'system' => 'S', 'user' => 'U'],
            ['key' => '1', 'system' => 'S', 'user' => 'U'],
        ]);

        $this->assertSame(['note' => 'ok'], $result['0']);
        $this->assertSame(['note' => 'ok'], $result['1']);
        Http::assertSentCount(2);
    }

    public function test_complete_many_passes_the_prompt_and_model_per_job(): void
    {
        Http::fake(function (Request $request) {
            return Http::response([
                'choices' => [['message' => ['content' => '{"ok":true}']]],
            ], 200);
        });

        $this->service->completeMany([
            ['key' => 'notes', 'system' => 'Page analyst', 'user' => 'DOCUMENTS', 'model' => 'openai/gpt-oss-120b'],
        ]);

        Http::assertSent(function (Request $request) {
            return $request['model'] === 'openai/gpt-oss-120b'
                && $request['messages'][0]['content'] === 'Page analyst'
                && $request['messages'][1]['content'] === 'DOCUMENTS';
        });
    }

    public function test_complete_many_retries_only_the_rate_limited_job(): void
    {
        $slowCalls = 0;
        Http::fake(function (Request $request) use (&$slowCalls) {
            if ($request['messages'][1]['content'] === 'Slow') {
                $slowCalls++;

                if ($slowCalls < 3) {
                    return Http::response(['error' => 'overloaded'], 429);
                }

                return Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]], 200);
            }

            return Http::response(['choices' => [['message' => ['content' => '{"fast":true}']]]], 200);
        });

        $result = $this->service->completeMany([
            ['key' => 'slow', 'system' => 'S', 'user' => 'Slow'],
            ['key' => 'fast', 'system' => 'S', 'user' => 'Fast'],
        ]);

        $this->assertSame(['slow' => ['ok' => true], 'fast' => ['fast' => true]], $result);
        $this->assertSame(3, $slowCalls);
    }

    public function test_complete_many_uses_retry_after_when_the_provider_asks_for_it(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            if ($calls < 2) {
                return Http::response(['error' => 'overloaded'], 429, ['Retry-After' => '1']);
            }

            return Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]], 200);
        });

        $result = $this->service->completeMany([
            ['key' => 'a', 'system' => 'S', 'user' => 'A'],
        ]);

        $this->assertSame(['a' => ['ok' => true]], $result);
    }

    public function test_complete_many_unparseable_job_fails_open_without_killing_siblings(): void
    {
        Http::fake(function (Request $request) {
            $user = $request['messages'][1]['content'];

            if ($user === 'Junk') {
                return Http::response(['choices' => [['message' => ['content' => 'no json here']]]], 200);
            }

            return Http::response(['choices' => [['message' => ['content' => '{"good":true}']]]], 200);
        });

        $result = $this->service->completeMany([
            ['key' => 'junk', 'system' => 'S', 'user' => 'Junk'],
            ['key' => 'good', 'system' => 'S', 'user' => 'Good'],
        ]);

        $this->assertNull($result['junk']);
        $this->assertSame(['good' => true], $result['good']);
    }

    public function test_complete_many_connection_failure_fails_open_for_that_job_only(): void
    {
        Http::fake(function (Request $request) {
            if ($request['messages'][1]['content'] === 'Down') {
                throw new \GuzzleHttp\Exception\ConnectException('down', $request->toPsrRequest());
            }

            return Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]], 200);
        });

        $result = $this->service->completeMany([
            ['key' => 'down', 'system' => 'S', 'user' => 'Down'],
            ['key' => 'ok', 'system' => 'S', 'user' => 'Ok'],
        ]);

        $this->assertNull($result['down']);
        $this->assertSame(['ok' => true], $result['ok']);
    }

    public function test_complete_many_missing_key_fails_open_without_any_request(): void
    {
        config(['services.ai.key' => '']);
        Http::fake();

        $result = $this->service->completeMany([
            ['key' => 'a', 'system' => 'S', 'user' => 'A'],
            ['key' => 'b', 'system' => 'S', 'user' => 'B'],
        ]);

        $this->assertSame(['a' => null, 'b' => null], $result);
        Http::assertNothingSent();
    }
}