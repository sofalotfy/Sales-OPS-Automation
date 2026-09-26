<?php

namespace Tests\Feature;

use App\Jobs\ProcessTriageJob;
use App\Models\ClassificationResult;
use App\Services\InquiryRunService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

/**
 * CRM enqueue surface (feature 013, US1; contracts/crm-ingest-web.md):
 * POST /inquiry/enqueue acknowledges one campaign lead with a run id and
 * dispatches the background pipeline. Idempotent on (campaign_id, lead_id) —
 * a re-submission is ack'd 409 and never re-runs (no double spend).
 */
class CrmEnqueueApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.crm_key' => 'crm-secret']);
        config(['services.crm_rate_limit' => 120]);
        Queue::fake();
    }

    private function keyHeaders(): array
    {
        return ['X-CRM-Key' => 'crm-secret'];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'campaign_id' => 'spring-2026-launch',
            'lead_id' => 'lead-4831',
            'first_name' => 'Sara',
            'last_name' => 'Ali',
            'email' => 'sara.ali@example.store',
            'phone_number' => '+201000000000',
            'company_name' => 'Bazaar Co.',
            'country_region' => 'EG',
            'message' => 'We run a 40-store retail chain and want a loyalty app.',
        ], $overrides);
    }

    public function test_enqueue_accepts_and_dispatches(): void
    {
        $response = $this->postJson('/inquiry/enqueue', $this->payload(), $this->keyHeaders());

        $response->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('campaign_id', 'spring-2026-launch')
            ->assertJsonPath('lead_id', 'lead-4831');
        $this->assertIsInt($response->json('inquiry_id'));

        // One queued row carrying the payload fields + the idempotency pair.
        $row = ClassificationResult::query()->firstOrFail();
        $this->assertSame('queued', $row->status->value);
        $this->assertSame('spring-2026-launch', $row->campaign_id);
        $this->assertSame('lead-4831', $row->lead_id);
        $this->assertSame('Sara', $row->first_name);
        $this->assertSame('Bazaar Co.', $row->company_name);
        $this->assertSame('We run a 40-store retail chain and want a loyalty app.', $row->inquiry_message);
        $this->assertNull($row->classification);
        $this->assertNull($row->final_score);

        Queue::assertPushed(ProcessTriageJob::class, 1);
        Queue::assertPushed(ProcessTriageJob::class, fn (ProcessTriageJob $job) => $job->inquiryRunId === (int) $row->id);
    }

    public function test_resubmission_is_idempotent_and_never_reruns(): void
    {
        $first = $this->postJson('/inquiry/enqueue', $this->payload(), $this->keyHeaders());
        $first->assertStatus(202);
        $inquiryId = $first->json('inquiry_id');

        $this->postJson('/inquiry/enqueue', $this->payload(), $this->keyHeaders())
            ->assertStatus(409)
            ->assertJsonPath('inquiry_id', $inquiryId)
            ->assertJsonPath('status', 'existing')
            ->assertJsonPath('campaign_id', 'spring-2026-launch')
            ->assertJsonPath('lead_id', 'lead-4831');

        $this->assertSame(1, ClassificationResult::query()->count());
        Queue::assertPushed(ProcessTriageJob::class, 1);
    }

    public function test_validation_errors_are_422_and_nothing_is_enqueued(): void
    {
        $cases = [
            'missing campaign_id' => $this->payload(['campaign_id' => null]),
            'missing lead_id' => $this->payload(['lead_id' => null]),
            'missing message' => $this->payload(['message' => null]),
            'bad email' => $this->payload(['email' => 'not-an-email']),
            'lead_id too long' => $this->payload(['lead_id' => str_repeat('x', 256)]),
        ];

        foreach ($cases as $name => $body) {
            $this->postJson('/inquiry/enqueue', $body, $this->keyHeaders())
                ->assertStatus(422)
                ->assertJsonStructure(['detail']);
        }

        $this->assertSame(0, ClassificationResult::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_blank_message_is_422(): void
    {
        $this->postJson('/inquiry/enqueue', $this->payload(['message' => '   ']), $this->keyHeaders())
            ->assertStatus(422)
            ->assertJsonPath('detail', 'The message must not be blank.');

        $this->assertSame(0, ClassificationResult::query()->count());
    }

    public function test_non_object_body_returns_400(): void
    {
        $this->call('POST', '/inquiry/enqueue', [], [], [], [
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-CRM-Key' => 'crm-secret',
        ], '12345')
            ->assertStatus(400)
            ->assertJsonPath('detail', 'A JSON object is required.');

        $this->assertSame(0, ClassificationResult::query()->count());
    }

    public function test_missing_key_is_rejected(): void
    {
        $this->postJson('/inquiry/enqueue', $this->payload())
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Invalid or missing CRM API key.');

        $this->assertSame(0, ClassificationResult::query()->count());
    }

    public function test_wrong_key_is_rejected(): void
    {
        $this->postJson('/inquiry/enqueue', $this->payload(), ['X-CRM-Key' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Invalid or missing CRM API key.');
    }

    public function test_exceeding_the_crm_limit_returns_the_contracted_429_body(): void
    {
        // Contracted envelope for the per-key limiter (contracts/crm-ingest-web.md):
        // a plain {"detail": "Too Many Requests"} — NOT Laravel's default debug
        // payload. The render callback (bootstrap/app.php) targets the exact
        // ThrottleRequestsException class the throttle middleware throws.
        RateLimiter::for('crm', fn () => Limit::perMinute(1)->by('crm-limit-test'));

        $this->postJson('/inquiry/enqueue', $this->payload(), $this->keyHeaders())->assertStatus(202);
        $this->postJson('/inquiry/enqueue', $this->payload(), $this->keyHeaders())
            ->assertStatus(429)
            ->assertExactJson(['detail' => 'Too Many Requests']);
    }

    public function test_unreachable_store_is_a_503_and_nothing_is_dropped_silently(): void
    {
        $service = $this->mock(InquiryRunService::class)
            ->shouldReceive('enqueue')
            ->once()
            ->andThrow(new RuntimeException('scoped store unreachable'))
            ->getMock();
        $this->app->instance(InquiryRunService::class, $service);

        $this->postJson('/inquiry/enqueue', $this->payload(), $this->keyHeaders())
            ->assertStatus(503)
            ->assertJsonPath('detail', 'Queue unavailable. Please retry in a moment.');

        Queue::assertNothingPushed();
    }
}
