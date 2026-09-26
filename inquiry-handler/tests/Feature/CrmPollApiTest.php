<?php

namespace Tests\Feature;

use App\Enums\Classification;
use App\Enums\InquiryRunStatus;
use App\Models\ClassificationResult;
use App\Services\InquiryRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CRM poll surface (feature 013, US1; contracts/crm-ingest-web.md):
 * GET /inquiry/{id} serves the shared-key-authenticated lifecycle state —
 * `result` is null while the run is in progress or failed, and is exactly the
 * synchronous triage envelope once `succeeded` (declines carry a disqualify
 * envelope with the refusal in `reply`).
 */
class CrmPollApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.crm_key' => 'crm-secret']);
        config(['services.crm_rate_limit' => 120]);
        config(['services.booking_url' => null]);
    }

    private function keyHeaders(): array
    {
        return ['X-CRM-Key' => 'crm-secret'];
    }

    private function inquiry(): array
    {
        return [
            'first_name' => 'Sara',
            'last_name' => 'Ali',
            'email' => 'sara.ali@example.store',
            'phone_number' => '+201000000000',
            'company_name' => 'Bazaar Co.',
            'country_region' => 'EG',
            'message' => 'We run a 40-store retail chain and want a loyalty app.',
        ];
    }

    /** @return int the enqueued run id */
    private function enqueue(string $campaign = 'spring-2026-launch', string $lead = 'lead-4831'): int
    {
        $result = $this->app->make(InquiryRunService::class)->enqueue($campaign, $lead, $this->inquiry());

        return (int) $result['record']->id;
    }

    private function poll(int $id): TestResponse
    {
        return $this->getJson("/inquiry/{$id}", $this->keyHeaders());
    }

    public function test_queued_row_returns_ack_without_result(): void
    {
        $id = $this->enqueue();

        $this->poll($id)
            ->assertOk()
            ->assertJsonPath('inquiry_id', $id)
            ->assertJsonPath('campaign_id', 'spring-2026-launch')
            ->assertJsonPath('lead_id', 'lead-4831')
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('result', null)
            ->assertJsonPath('error', null);
    }

    public function test_processing_row_still_defers_the_result(): void
    {
        $id = $this->enqueue();
        $this->app->make(InquiryRunService::class)->markStatus($id, InquiryRunStatus::Processing);

        $this->poll($id)
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('result', null);
    }

    public function test_succeeded_row_returns_the_full_triage_envelope(): void
    {
        $id = $this->enqueue();

        $this->app->make(InquiryRunService::class)->markSucceeded($id, [
            'retrieved_context' => ['result_count' => 0, 'results' => []],
            'factor_scores' => ['company_size' => ['score' => 85, 'weight' => 1, 'reasoning' => 'Large enterprise.', 'meta' => null]],
            'dropped_factors' => [],
            'final_score' => 85.0,
            'classification' => Classification::High,
            'reasoning' => 'Weighted score 85.00 maps to high.',
            'system_prompt' => 'The fixed system prompt.',
            'web_research_outcome' => 'accept',
            'web_research_reason' => 'Public findings support a profile.',
            'web_research' => ['criteria' => ['company' => ['name' => 'Bazaar Co.']], 'findings' => ['outcome' => 'completed', 'summary' => 'Large retail chain.'], 'audit' => []],
            'scope_check_outcome' => 'accept',
            'scope_check_reason' => 'Within the served scope.',
            'refusal' => null,
        ]);

        $this->poll($id)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('error', null)
            ->assertJsonPath('result.classification', 'high')
            ->assertJsonPath('result.score', 85)
            ->assertJsonPath('result.factor_scores.company_size.score', 85)
            ->assertJsonPath('result.dropped_factors', [])
            ->assertJsonPath('result.reply', 'Thanks for reaching out. A member of our team will follow up with the details.')
            ->assertJsonPath('result.reasoning', 'Weighted score 85.00 maps to high.')
            ->assertJsonPath('result.context.inquiry.email', 'sara.ali@example.store')
            ->assertJsonPath('result.context.system_prompt', 'The fixed system prompt.')
            ->assertJsonPath('result.context.web_research.outcome', 'accept')
            ->assertJsonPath('result.context.scope_check.outcome', 'accept');
    }

    public function test_decline_row_returns_a_disqualify_envelope_with_the_refusal(): void
    {
        $id = $this->enqueue();

        $this->app->make(InquiryRunService::class)->markSucceeded($id, [
            'retrieved_context' => ['result_count' => 0, 'results' => []],
            'factor_scores' => [],
            'dropped_factors' => [],
            'final_score' => 0.0,
            'classification' => Classification::Disqualify,
            'reasoning' => 'Research shows the company is defunct.',
            'web_research_outcome' => 'decline',
            'web_research_reason' => 'Research shows the company is defunct.',
            'web_research' => ['criteria' => [], 'findings' => [], 'audit' => null],
            'system_prompt' => 'The fixed system prompt.',
            'refusal' => 'Thank you for reaching out, but we are not able to help with this inquiry.',
        ]);

        $this->poll($id)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('result.classification', 'disqualify')
            ->assertJsonPath('result.score', 0)
            ->assertJsonPath('result.reply', 'Thank you for reaching out, but we are not able to help with this inquiry.')
            ->assertJsonPath('result.context.web_research.outcome', 'decline');
    }

    public function test_failed_row_returns_the_error_without_a_result(): void
    {
        $id = $this->enqueue();
        $this->app->make(InquiryRunService::class)->markFailed($id, 'Provider config invalid.');

        $this->poll($id)
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('result', null)
            ->assertJsonPath('error', 'Provider config invalid.');
    }

    public function test_unknown_inquiry_returns_404(): void
    {
        $this->poll(424242)
            ->assertNotFound()
            ->assertJsonPath('detail', 'Inquiry not found.');
    }

    public function test_missing_key_is_rejected(): void
    {
        $this->getJson('/inquiry/1')
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Invalid or missing CRM API key.');
    }

    public function test_wrong_key_is_rejected(): void
    {
        $this->getJson('/inquiry/1', ['X-CRM-Key' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Invalid or missing CRM API key.');
    }

    public function test_polling_never_creates_rows(): void
    {
        $this->poll(1);
        $this->assertSame(0, ClassificationResult::query()->count());
    }
}
