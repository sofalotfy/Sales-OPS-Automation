<?php

namespace Tests\Feature;

use App\Models\ClassificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs as Stubs;
use Tests\TestCase;

/**
 * Scope gate middleware feature tests (feature 007).
 *
 * The gate is DISABLED globally in phpunit.xml so the pre-existing contract
 * 2.0 tests keep their exact behavior; every test here re-enables it and
 * asserts the accept / decline / indeterminate / accountability flows
 * (contracts/inquiry-web.md v2.1).
 */
class ScopeGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scope_gate.enabled' => true]);
    }

    private function payload(string $message, array $overrides = []): array
    {
        return array_merge([
            'message' => $message,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ], $overrides);
    }

    // ---------------------------------------------------------------- accept

    public function test_in_scope_inquiry_passes_through_with_accept_marker(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeScopeAccept();

        $response = $this->postJson('/inquiry/triage', [
            'message' => 'Do you build enterprise web applications for B2B companies?',
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
            ->assertJsonPath('reply', config('scoring.replies.low'))
            ->assertJsonPath('context.scope_check.outcome', 'accept')
            ->assertJsonPath('context.scope_check.reason', "The inquiry is within the company's served scope.")
            ->assertJsonPath('context.inquiry.first_name', 'Jane')
            ->assertJsonPath('context.inquiry.last_name', 'Doe')
            ->assertJsonPath('context.inquiry.email', 'jane@example.com')
            ->assertJsonMissingPath('context.inquiry.name')
            ->assertJsonPath('context.retrieved_context.result_count', 0);

        $this->assertDatabaseHas('classification_results', [
            'inquiry_message' => 'Do you build enterprise web applications for B2B companies?',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_number' => '+1 555 0132',
            'company_name' => 'Example Corp',
            'country_region' => 'United Kingdom',
            'classification' => 'low',
            'scope_check_outcome' => 'accept',
            'scope_check_reason' => "The inquiry is within the company's served scope.",
            'refusal' => null,
        ]);
    }

    // ---------------------------------------------------------------- decline

    public function test_out_of_scope_inquiry_is_refused_with_the_reason(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeScopeDecline('We only handle enterprise software tooling, not appliance repair.');

        $response = $this->postJson('/inquiry/triage', [
            'message' => 'Can you fix my washing machine?',
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'email' => 'bob@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('classification', 'disqualify')
            ->assertJsonPath('score', 0)
            ->assertJsonPath('factor_scores', [])
            ->assertJsonPath('dropped_factors', [])
            ->assertJsonPath('reply', 'We only handle enterprise software tooling, not appliance repair.')
            ->assertJsonPath('reasoning', 'We only handle enterprise software tooling, not appliance repair.')
            ->assertJsonPath('context.scope_check.outcome', 'decline')
            ->assertJsonPath('context.scope_check.reason', 'We only handle enterprise software tooling, not appliance repair.')
            ->assertJsonPath('context.inquiry.first_name', 'Bob')
            ->assertJsonPath('context.inquiry.email', 'bob@example.com');

        $this->assertDatabaseHas('classification_results', [
            'inquiry_message' => 'Can you fix my washing machine?',
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'email' => 'bob@example.com',
            'classification' => 'disqualify',
            'final_score' => 0,
            'scope_check_outcome' => 'decline',
            'refusal' => 'We only handle enterprise software tooling, not appliance repair.',
        ]);
    }

    public function test_declined_inquiry_never_reaches_the_classification_engine(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeScopeDecline();

        $this->postJson('/inquiry/triage', $this->payload('Fix my gutter.'))
            ->assertOk()
            ->assertJsonPath('classification', 'disqualify');

        $this->assertSame(1, ClassificationResult::query()->count());
        $this->assertDatabaseHas('classification_results', ['classification' => 'disqualify']);
    }

    public function test_repeated_out_of_scope_inquiries_are_refused_consistently(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeScopeDecline('Not something we sell.');

        foreach (['Fix my washing machine.', 'Fix my gutters.', 'Fix my boiler.'] as $message) {
            $this->postJson('/inquiry/triage', $this->payload($message))
                ->assertOk()
                ->assertJsonPath('classification', 'disqualify')
                ->assertJsonPath('context.scope_check.outcome', 'decline')
                ->assertJsonPath('reply', 'Not something we sell.');
        }

        $this->assertSame(3, ClassificationResult::query()->count());
    }

    // ----------------------------------------------------------- indeterminate

    public function test_gate_uses_static_scope_context_when_rag_is_down(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagFailure(503);
        Stubs::fakeScopeAccept();

        $this->postJson('/inquiry/triage', $this->payload('Do you sell CRM automation?'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('context.scope_check.outcome', 'accept');
    }

    public function test_gate_uses_static_scope_context_regardless_of_rag_results(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([]);
        Stubs::fakeScopeAccept();

        $this->postJson('/inquiry/triage', $this->payload('Do you sell CRM automation?'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('context.scope_check.outcome', 'accept');
    }

    public function test_ai_failure_fails_open_to_classification(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeZaiFailure(500);

        $this->postJson('/inquiry/triage', $this->payload('Do you sell CRM automation?'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('context.scope_check.outcome', 'indeterminate');
    }

    public function test_missing_ai_key_fails_open_to_classification(): void
    {
        config(['services.zai.key' => '']);

        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);

        $this->postJson('/inquiry/triage', $this->payload('Do you sell CRM automation?'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('context.scope_check.outcome', 'indeterminate');
    }

    public function test_ai_unparseable_output_fails_open_to_classification(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeZaiJson('this is not json');

        $this->postJson('/inquiry/triage', $this->payload('Do you sell CRM automation?'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('context.scope_check.outcome', 'indeterminate');
    }

    public function test_ai_missing_in_scope_field_fails_open_to_classification(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeZaiJson(json_encode(['reason' => 'no verdict here']));

        $this->postJson('/inquiry/triage', $this->payload('Do you sell CRM automation?'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('context.scope_check.outcome', 'indeterminate');
    }

    // ------------------------------------------------------- invalid payloads

    public function test_non_object_json_body_still_returns_400_before_the_gate(): void
    {
        $response = $this->call('POST', '/inquiry/triage', [], [], [], [
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '12345');

        $response->assertStatus(400)
            ->assertJsonPath('detail', 'A JSON object is required.');

        Http::assertNothingSent();
    }

    public function test_blank_message_still_returns_422_before_the_gate(): void
    {
        $response = $this->postJson('/inquiry/triage', $this->payload('   '));

        $response->assertStatus(422)
            ->assertJsonPath('detail', 'The message must not be blank.');

        Http::assertNothingSent();
    }

    // ----------------------------------------------------------- kill switch

    public function test_gate_disabled_bypasses_scope_check_without_marker(): void
    {
        config(['scope_gate.enabled' => false]);

        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);

        $this->postJson('/inquiry/triage', $this->payload('Fix my washing machine.'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonMissingPath('context.scope_check');
    }

    // ---------------------------------------------------------- accountability

    public function test_declined_inquiry_is_reviewable_via_the_admin_api(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeScopeDecline('We do not offer this service.');

        $this->postJson('/inquiry/triage', [
            'message' => 'Fix my washing machine.',
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'email' => 'bob@example.com',
        ])->assertOk();

        Stubs::authVerifyOk();

        $listing = $this->getJson('/admin/classification-results', [
            'Authorization' => 'Bearer '.Stubs::token(),
            'Accept' => 'application/json',
        ]);

        $listing->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.inquiry_message', 'Fix my washing machine.')
            ->assertJsonPath('items.0.classification', 'disqualify')
            ->assertJsonPath('items.0.scope_check_outcome', 'decline');
    }
}
