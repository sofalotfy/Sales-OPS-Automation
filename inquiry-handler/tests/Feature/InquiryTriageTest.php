<?php

namespace Tests\Feature;

use App\Models\ClassificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs as Stubs;
use Tests\TestCase;

/**
 * New empty-catalog contract (contracts/inquiry-web.md v2.0): with no factors
 * registered the endpoint returns `low` / 0.00 plus the response envelope
 * (classification/score/factor_scores/dropped_factors/reply/reasoning/context).
 * No `disposition` key is emitted.
 */
class InquiryTriageTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_catalog_returns_low_with_contact_fields_echoed(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);

        $response = $this->postJson('/inquiry/triage', [
            'message' => 'Do you offer annual maintenance contracts for heating boilers?',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_number' => '+1 555 0132',
            'company_name' => 'Example Corp',
            'country_region' => 'United Kingdom',
        ]);

        $response->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('score', 0)
            ->assertJsonPath('factor_scores', [])
            ->assertJsonPath('dropped_factors', [])
            ->assertJsonPath('reasoning', 'No factors are registered; catalog is empty.')
            ->assertJsonMissingPath('disposition')
            ->assertJsonPath('context.inquiry.first_name', 'Jane')
            ->assertJsonPath('context.inquiry.last_name', 'Doe')
            ->assertJsonPath('context.inquiry.email', 'jane@example.com')
            ->assertJsonPath('context.inquiry.phone_number', '+1 555 0132')
            ->assertJsonPath('context.inquiry.company_name', 'Example Corp')
            ->assertJsonPath('context.inquiry.country_region', 'United Kingdom')
            ->assertJsonMissingPath('context.inquiry.name')
            ->assertJsonPath('context.retrieved_context.result_count', 0)
            ->assertJsonPath('context.retrieved_context.results', [])
            ->assertJsonPath('context.system_prompt', (new \App\Triage\SystemPrompt)->content());
    }

    public function test_low_reply_is_the_configured_placeholder(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);

        $response = $this->postJson('/inquiry/triage', [
            'message' => 'I want to book a consultation.',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('reply', config('scoring.replies.low'))
            ->assertJsonPath('classification', 'low');
    }

    public function test_required_contact_fields_only_submission_works(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery();

        $response = $this->postJson('/inquiry/triage', [
            'message' => 'Can you fix my bicycle?',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('context.inquiry.first_name', 'Jane')
            ->assertJsonPath('context.inquiry.last_name', 'Doe')
            ->assertJsonPath('context.inquiry.email', 'jane@example.com')
            ->assertJsonPath('context.inquiry.phone_number', null)
            ->assertJsonPath('context.inquiry.company_name', null)
            ->assertJsonPath('context.inquiry.country_region', null);
    }

    public function test_missing_first_name_returns_422(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery();

        $response = $this->postJson('/inquiry/triage', [
            'message' => 'Do you offer annual maintenance contracts?',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('detail', 'The first name field is required.');

        Http::assertNothingSent();
    }

    public function test_classification_run_is_persisted_to_the_log(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);

        $this->postJson('/inquiry/triage', [
            'message' => 'Do you offer annual maintenance contracts for heating boilers?',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('classification_results', [
            'inquiry_message' => 'Do you offer annual maintenance contracts for heating boilers?',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_number' => null,
            'company_name' => null,
            'country_region' => null,
            'classification' => 'low',
            'final_score' => 0,
        ]);
        $this->assertSame(1, ClassificationResult::query()->count());
        $this->assertSame(
            (new \App\Triage\SystemPrompt)->content(),
            ClassificationResult::query()->first()->system_prompt,
        );
    }

    public function test_empty_catalog_never_calls_the_ai_provider(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeZai('booking');

        $this->postJson('/inquiry/triage', [
            'message' => 'Hello there.',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('classification', 'low');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'z.ai'));
    }

    public function test_blank_message_returns_422_with_clear_detail(): void
    {
        $response = $this->postJson('/inquiry/triage', [
            'message' => '   ',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('detail', 'The message must not be blank.');

        Http::assertNothingSent();
    }

    public function test_missing_message_returns_422(): void
    {
        $response = $this->postJson('/inquiry/triage', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('detail', 'The message field is required.');

        Http::assertNothingSent();
    }

    public function test_oversized_message_returns_422(): void
    {
        $response = $this->postJson('/inquiry/triage', [
            'message' => str_repeat('a', 4001),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_malformed_email_returns_422(): void
    {
        $response = $this->postJson('/inquiry/triage', [
            'message' => 'Hello',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'user@',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('detail', 'The email must be a valid email address.');
    }

    public function test_non_json_body_returns_400(): void
    {
        $response = $this->call('POST', '/inquiry/triage', [], [], [], [
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '12345');

        $response->assertStatus(400)
            ->assertJsonPath('detail', 'A JSON object is required.');
    }
}