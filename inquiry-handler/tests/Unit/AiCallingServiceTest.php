<?php

namespace Tests\Unit;

use App\Services\AiCallingService;
use App\Triage\PromptBuilder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AiCallingService::complete() transport tests (feature 010 / FR-011):
 * transient provider overload rides through a bounded retry; hard failures and
 * missing keys still fail open to `null`; the research model override lands in
 * the request body.
 */
class AiCallingServiceTest extends TestCase
{
    private AiCallingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.zai.key' => 'test-key']);
        config(['services.zai.url' => 'https://zai.test/chat/completions']);
        config(['services.zai.model' => 'glm-4.5-flash']);
        config(['services.zai.timeout' => 5]);
        $this->service = new AiCallingService(new PromptBuilder('scope'));
    }

    public function test_transient_overload_is_retried_then_succeeds(): void
    {
        Http::fake([
            'zai.test/*' => Http::sequence()
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
            'zai.test/*' => Http::response(['error' => ['code' => '1305']], 429),
        ]);

        $this->assertNull($this->service->complete('Sys', 'User'));
        Http::assertSentCount(3);
    }

    public function test_hard_error_status_is_not_retried(): void
    {
        Http::fake([
            'zai.test/*' => Http::response(['error' => ['message' => 'Bad request.']], 400),
        ]);

        $this->assertNull($this->service->complete('Sys', 'User'));
        Http::assertSentCount(1);
    }

    public function test_missing_key_fails_open_without_any_request(): void
    {
        config(['services.zai.key' => '']);
        Http::fake();

        $this->assertNull($this->service->complete('Sys', 'User'));
        Http::assertNothingSent();
    }

    public function test_research_model_override_reaches_the_request_body(): void
    {
        Http::fake([
            'zai.test/*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]], 200),
        ]);

        $this->service->complete('Sys', 'User', model: 'glm-4.7-flash');

        Http::assertSent(fn ($request) => $request['model'] === 'glm-4.7-flash');
    }

    public function test_markdown_wrapped_json_is_extracted(): void
    {
        Http::fake([
            'zai.test/*' => Http::response([
                'choices' => [['message' => ['content' => "Here you go:\n```json\n{\"outcome\":\"ok\",\"keep\":[1]}\n```"]]],
            ], 200),
        ]);

        $this->assertSame(['outcome' => 'ok', 'keep' => [1]], $this->service->complete('Sys', 'User'));
    }

    public function test_narrated_json_is_excavated(): void
    {
        Http::fake([
            'zai.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'Based on the candidates, this is my answer {"summary":"x","sources":[]} kind regards']]],
            ], 200),
        ]);

        $this->assertSame(['summary' => 'x', 'sources' => []], $this->service->complete('Sys', 'User'));
    }

    public function test_no_json_in_content_fails_open(): void
    {
        Http::fake([
            'zai.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'I cannot answer that right now.']]],
            ], 200),
        ]);

        $this->assertNull($this->service->complete('Sys', 'User'));
    }
}