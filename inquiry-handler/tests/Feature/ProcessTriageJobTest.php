<?php

namespace Tests\Feature;

use App\Enums\InquiryRunStatus;
use App\Jobs\ProcessTriageJob;
use App\Models\ClassificationResult;
use App\ScopeGate\ScopeCheckService;
use App\Scoring\CompanySizeFactor;
use App\Scoring\FactorRegistry;
use App\Scoring\IndustrySectorFactor;
use App\Scoring\ScoringEngine;
use App\Services\InquiryRunService;
use App\Services\NotifyClientGrowthDirector;
use App\Triage\SystemPrompt;
use App\WebResearch\ResearchAgent;
use App\WebResearch\ResearchOutcome;
use App\WebResearch\ResearchResult;
use App\WebResearch\WebResearchProvider;
use App\WebResearch\WebResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\Support\PinsCompanySizeCatalog;
use Tests\Feature\Support\SpyNotifyClientGrowthDirector;
use Tests\Feature\Support\UpstreamStubs as Stubs;
use Tests\TestCase;

/**
 * ProcessTriageJob tests (feature 013, US1): the Redis worker replays the
 * synchronous triage chain against one run row — web research → scope gate →
 * scoring — advancing status per stage and landing each stage's columns. It is
 * run synchronously here (QUEUE_CONNECTION=sync) against faked upstreams:
 *
 *  - a full pipeline lands web + scope + result columns on the same row and
 *    completes it `succeeded`;
 *  - a research decline and a scope decline each complete the run `succeeded`
 *    with a disqualify envelope + refusal, never running later stages;
 *  - a terminal row is never re-run (US4);
 *  - a failure on its last attempt marks the run `failed` (US5); and
 *  - every stage that cannot produce a verdict fails open to the next one.
 */
class ProcessTriageJobTest extends TestCase
{
    use PinsCompanySizeCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinCompanySizeCatalog();
        config(['web_research.enabled' => false]);
        config(['scope_gate.enabled' => false]);
    }

    private function payload(string $message = 'Do you build enterprise web applications?'): array
    {
        return [
            'message' => $message,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_number' => '+1 555 0132',
            'company_name' => 'Example Corp',
            'country_region' => 'United Kingdom',
        ];
    }

    private function enqueue(string $campaign = 'camp-1', string $lead = 'lead-1'): int
    {
        $result = $this->app->make(InquiryRunService::class)->enqueue($campaign, $lead, $this->payload());

        return (int) $result['record']->id;
    }

    /** @param callable(array): array $research the provider implementation */
    private function bindProvider(callable $research): void
    {
        $this->app->bind(WebResearchProvider::class, fn () => new class($research) implements WebResearchProvider
        {
            public function __construct(private $research) {}

            public function research(array $criteria): array
            {
                return ($this->research)($criteria);
            }
        });
    }

    private function bindAgent(ResearchResult $result): void
    {
        $this->app->bind(ResearchAgent::class, fn () => Stubs::researchAgent($result));
    }

    /**
     * Discriminating AI stub for the full pipeline: the scope check asks a
     * different question than the company-size factor, so route by the prompt.
     */
    private function fakeAiForFullPipeline(): void
    {
        Http::fake(function (Request $request) {
            if ($request->url() !== Stubs::aiUrl()) {
                return null;
            }

            $body = (string) json_encode($request->data());

            if (str_contains($body, 'size_band')) {
                return Http::response([
                    'choices' => [['message' => ['content' => json_encode([
                        'score' => 85,
                        'size_band' => 'large',
                        'employee_count' => 5000,
                        'reasoning' => 'A multi-country retail chain with thousands of staff.',
                    ])]]],
                ], 200);
            }

            return Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'in_scope' => true,
                    'reason' => 'Within the served scope.',
                ])]]],
            ], 200);
        });
    }

    public function test_full_pipeline_updates_one_row_to_succeeded(): void
    {
        config(['web_research.enabled' => true]);
        config(['scope_gate.enabled' => true]);

        $this->bindProvider(fn (array $criteria) => [
            'company' => ['results' => [['title' => 'Example Corp', 'url' => 'https://example.com/about', 'snippet' => 'Fintech with 5,000 employees']]],
            'person' => ['results' => []],
        ]);
        $this->bindAgent(new ResearchResult(
            ResearchOutcome::Completed,
            'Example Corp is a large UK enterprise.',
            [['title' => 'About Example Corp', 'url' => 'https://example.com/about']],
            null,
            audit: [],
        ));
        $this->fakeAiForFullPipeline();

        $id = $this->enqueue();

        ProcessTriageJob::dispatchSync($id);

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $row->status->value);
        $this->assertSame('camp-1', $row->campaign_id);
        $this->assertSame('lead-1', $row->lead_id);
        $this->assertSame('accept', $row->web_research_outcome);
        $this->assertSame('accept', $row->scope_check_outcome);
        $this->assertSame('high', $row->classification->value);
        $this->assertSame(85.0, $row->final_score);

        // Exactly one row exists for the entire run — no stage wrote a second.
        $this->assertSame(1, ClassificationResult::query()->count());

        // The poll envelope carries the stage context sections the job built.
        $envelope = $this->app->make(InquiryRunService::class)->pollPayload($row)['result'];
        $this->assertSame('high', $envelope['classification']);
        $this->assertSame('accept', $envelope['context']['web_research']['outcome']);
        $this->assertSame('accept', $envelope['context']['scope_check']['outcome']);
        $this->assertSame('Example Corp', $envelope['context']['web_research']['criteria']['company']['name']);
    }

    public function test_research_decline_completes_run_with_refusal(): void
    {
        config(['web_research.enabled' => true]);

        $this->bindProvider(fn (array $criteria) => [
            'company' => ['is_defunct' => true],
            'decline' => [
                'reason' => 'Research shows the company is defunct and no longer operating.',
                'refusal' => 'Thank you for reaching out, but we are not able to help with this inquiry.',
            ],
        ]);
        $this->bindAgent(ResearchResult::notFound('not reached'));

        $id = $this->enqueue();

        ProcessTriageJob::dispatchSync($id);

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $row->status->value);
        $this->assertSame('decline', $row->web_research_outcome);
        $this->assertSame('disqualify', $row->classification->value);
        $this->assertSame(0.0, $row->final_score);
        $this->assertSame('Thank you for reaching out, but we are not able to help with this inquiry.', $row->refusal);
        $this->assertNull($row->scope_check_outcome);
        $this->assertSame(1, ClassificationResult::query()->count());

        $envelope = $this->app->make(InquiryRunService::class)->pollPayload($row)['result'];
        $this->assertSame('disqualify', $envelope['classification']);
        $this->assertSame('Thank you for reaching out, but we are not able to help with this inquiry.', $envelope['reply']);
        $this->assertSame([], $envelope['factor_scores']);
    }

    public function test_scope_decline_completes_run_with_refusal(): void
    {
        config(['scope_gate.enabled' => true]);

        // The scope check is the only AI call: it judges the inquiry out of scope.
        Http::fake([
            Stubs::aiUrl() => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'in_scope' => false,
                    'reason' => 'The inquiry is outside the served scope.',
                ])]]],
            ], 200),
        ]);

        $id = $this->enqueue();

        ProcessTriageJob::dispatchSync($id);

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $row->status->value);
        $this->assertSame('decline', $row->scope_check_outcome);
        $this->assertSame('disqualify', $row->classification->value);
        $this->assertSame(0.0, $row->final_score);
        $this->assertSame('The inquiry is outside the served scope.', $row->refusal);
        $this->assertNull($row->web_research_outcome);
        $this->assertNotNull($row->retrieved_context);
        $this->assertSame(1, ClassificationResult::query()->count());

        $envelope = $this->app->make(InquiryRunService::class)->pollPayload($row)['result'];
        $this->assertSame('decline', $envelope['context']['scope_check']['outcome']);
        $this->assertSame('The inquiry is outside the served scope.', $envelope['reply']);
    }

    public function test_declined_inquiry_is_reviewable_via_the_admin_api(): void
    {
        config(['scope_gate.enabled' => true]);

        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeScopeDecline('We do not offer this service.');

        $id = $this->enqueue();
        ProcessTriageJob::dispatchSync($id);

        $this->assertSame('disqualify', ClassificationResult::findOrFail($id)->classification->value);

        Stubs::authVerifyOk();

        $this->getJson('/admin/classification-results', [
            'Authorization' => 'Bearer '.Stubs::token(),
            'Accept' => 'application/json',
        ])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.inquiry_message', 'Do you build enterprise web applications?')
            ->assertJsonPath('items.0.classification', 'disqualify')
            ->assertJsonPath('items.0.scope_check_outcome', 'decline');
    }

    public function test_terminal_run_is_never_rerun(): void
    {
        // Enqueue, then complete the run as a research decline before the job
        // would have run.
        $id = $this->enqueue();
        $service = $this->app->make(InquiryRunService::class);
        $service->markSucceeded($id, [
            'retrieved_context' => ['result_count' => 0, 'results' => []],
            'factor_scores' => [],
            'dropped_factors' => [],
            'final_score' => 0.0,
            'classification' => 'disqualify',
            'reasoning' => 'declined elsewhere',
            'refusal' => 'Not able to help.',
        ]);

        $before = ClassificationResult::findOrFail($id);
        $stamp = $before->updated_at;

        ProcessTriageJob::dispatchSync($id);

        $after = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $after->status->value);
        $this->assertSame('disqualify', $after->classification->value);
        $this->assertSame('declined elsewhere', $after->reasoning);
        $this->assertNull($after->web_research_outcome);
        $this->assertSame($stamp->toDateTimeString(), $after->updated_at->toDateTimeString());
    }

    public function test_queued_job_properties_keep_the_crash_recovery_ladder(): void
    {
        // US4: redis retry_after (900) > worker --timeout (600) > job timeout
        // (590). If a job ever outlived the worker lease, a crash could leave
        // a re-queued and a live copy running the same lead at once.
        $job = new ProcessTriageJob(1);

        $this->assertSame(5, $job->tries);
        $this->assertSame([5, 15, 30, 60], $job->backoff);
        $this->assertSame(590, $job->timeout);

        $retryAfter = (int) env('REDIS_QUEUE_RETRY_AFTER', 900);
        $this->assertGreaterThan(600, $retryAfter);
        $this->assertGreaterThan($job->timeout, 600);
        $this->assertGreaterThan($job->timeout, $retryAfter);
    }

    public function test_unrecoverable_last_attempt_marks_the_run_failed(): void
    {
        $id = $this->enqueue();

        // Simulate an unrecoverable stage failure: the very first transition
        // blows up. With tries=1 this is the last attempt, so the job records
        // `failed` + error on the row before rethrowing (US5).
        $this->partialMock(InquiryRunService::class, function ($mock) use ($id) {
            $mock->shouldReceive('markStatus')
                ->with($id, InquiryRunStatus::Processing)
                ->andThrow(new RuntimeException('boom'));
        });

        $job = new class($id) extends ProcessTriageJob
        {
            public function attempts(): int
            {
                return 1;
            }
        };
        $job->tries = 1;

        try {
            $job->handle(
                $this->app->make(InquiryRunService::class),
                $this->app->make(WebResearchService::class),
                $this->app->make(ScopeCheckService::class),
                $this->app->make(ScoringEngine::class),
                $this->app->make(SystemPrompt::class),
            );
            $this->fail('The job was expected to rethrow.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('failed', $row->status->value);
        $this->assertSame('boom', $row->error);
    }

    // =========================================================================
    // Degradation contracts
    //
    // Every stage that cannot produce a verdict must fail open to the next one
    // rather than taking the run down: a research or scope failure leaves the
    // stage `indeterminate`, and an unusable context or provider leaves the
    // run `disqualify` with a zero score instead of a 500. These used to be
    // asserted through the synchronous HTTP response; they are asserted on the
    // run row + poll envelope now that the worker owns the pipeline.
    // =========================================================================

    public function test_research_not_found_is_an_accept_with_an_explicit_statement(): void
    {
        config(['web_research.enabled' => true]);

        $this->bindProvider(fn (array $criteria) => ['company' => ['results' => []], 'person' => ['results' => []]]);
        $this->bindAgent(ResearchResult::notFound('No public information about Example Corp / Jane Doe could be found to establish a profile.'));
        Stubs::fakeAiFactorScore(0);

        $id = $this->enqueue();

        ProcessTriageJob::dispatchSync($id);

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $row->status->value);
        $this->assertSame('accept', $row->web_research_outcome);

        $envelope = $this->app->make(InquiryRunService::class)->pollPayload($row)['result'];
        $this->assertSame('accept', $envelope['context']['web_research']['outcome']);
        $this->assertSame('not_found', $envelope['context']['web_research']['findings']['outcome']);
        $this->assertSame(
            'No public information about Example Corp / Jane Doe could be found to establish a profile.',
            $envelope['context']['web_research']['findings']['summary'],
        );
        $this->assertEquals(0, $envelope['factor_scores']['company_size']['score']);
        $this->assertSame(
            'Company size could not be estimated: no public information about the company was found.',
            $envelope['factor_scores']['company_size']['reasoning'],
        );
    }

    public function test_research_provider_failure_fails_open_to_classification(): void
    {
        config(['web_research.enabled' => true]);

        $this->bindProvider(fn (array $criteria) => throw new RuntimeException('search provider down'));
        $this->bindAgent(ResearchResult::notFound('not reached'));
        Stubs::fakeAiFactorScore(0);

        $id = $this->enqueue();

        ProcessTriageJob::dispatchSync($id);

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $row->status->value);
        $this->assertSame('indeterminate', $row->web_research_outcome);
        $this->assertSame('disqualify', $row->classification->value);

        $envelope = $this->app->make(InquiryRunService::class)->pollPayload($row)['result'];
        $this->assertSame('indeterminate', $envelope['context']['web_research']['outcome']);
        $this->assertSame([], $envelope['context']['web_research']['findings']);
    }

    public function test_research_agent_failure_fails_open_to_classification(): void
    {
        config(['web_research.enabled' => true]);

        $this->bindProvider(fn (array $criteria) => [
            'company' => ['results' => [['title' => 'Example Corp', 'url' => 'https://example.com', 'snippet' => '']]],
            'person' => ['results' => []],
        ]);
        $this->bindAgent(ResearchResult::indeterminate('The research filter was unavailable.'));
        Stubs::fakeAiFactorScore(0);

        $id = $this->enqueue();

        ProcessTriageJob::dispatchSync($id);

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $row->status->value);
        $this->assertSame('indeterminate', $row->web_research_outcome);
        $this->assertSame([], $row->web_research['findings']);
        $this->assertSame('disqualify', $row->classification->value);
    }

    public function test_scope_gate_ai_failure_fails_open_to_classification(): void
    {
        config(['scope_gate.enabled' => true]);

        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeAiFailure(500);

        $id = $this->enqueue();

        ProcessTriageJob::dispatchSync($id);

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $row->status->value);
        $this->assertSame('indeterminate', $row->scope_check_outcome);
        $this->assertSame('disqualify', $row->classification->value);

        $envelope = $this->app->make(InquiryRunService::class)->pollPayload($row)['result'];
        $this->assertSame('indeterminate', $envelope['context']['scope_check']['outcome']);
        $this->assertEquals(0, $envelope['factor_scores']['company_size']['score']);
    }

    public function test_ai_http_error_degrades_to_disqualify_with_empty_factors(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeAiFailure(500);

        $id = $this->enqueue();

        ProcessTriageJob::dispatchSync($id);

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $row->status->value);
        $this->assertSame('disqualify', $row->classification->value);
        $this->assertEquals(0.0, $row->final_score);

        $envelope = $this->app->make(InquiryRunService::class)->pollPayload($row)['result'];
        $this->assertSame('disqualify', $envelope['classification']);
        $this->assertEquals(0, $envelope['factor_scores']['company_size']['score']);
        $this->assertSame('Do you build enterprise web applications?', $envelope['context']['inquiry']['message']);
    }

    public function test_rag_unavailable_degrades_with_context_preserved(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagFailure(503);
        Stubs::fakeAi('booking');

        $id = $this->enqueue();

        ProcessTriageJob::dispatchSync($id);

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $row->status->value);
        $this->assertSame('disqualify', $row->classification->value);

        $envelope = $this->app->make(InquiryRunService::class)->pollPayload($row)['result'];
        $this->assertSame(0, $envelope['context']['retrieved_context']['result_count']);
        $this->assertSame([], $envelope['context']['retrieved_context']['results']);
    }

    public function test_dropped_sector_factor_is_recorded_and_the_run_still_succeeds(): void
    {
        // The real catalog (company_size + industry_sector), not the pinned one:
        // this is the shape where a factor cannot classify and drops out.
        $spy = new SpyNotifyClientGrowthDirector;
        $this->app->instance(NotifyClientGrowthDirector::class, $spy);

        $registry = new FactorRegistry;
        $registry->add($this->app->make(CompanySizeFactor::class));
        $registry->add($this->app->make(IndustrySectorFactor::class));
        $this->app->instance(FactorRegistry::class, $registry);

        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeSectorClassification([], ['Media & Entertainment'], 'No plausible catalog sector.');

        $id = $this->enqueue();

        ProcessTriageJob::dispatchSync($id);

        $row = ClassificationResult::findOrFail($id);
        $this->assertSame('succeeded', $row->status->value);
        $this->assertSame('disqualify', $row->classification->value);

        $envelope = $this->app->make(InquiryRunService::class)->pollPayload($row)['result'];
        $this->assertArrayNotHasKey('industry_sector', $envelope['factor_scores']);

        $drop = collect($envelope['dropped_factors'])->firstWhere('name', 'industry_sector');
        $this->assertNotNull($drop, 'industry_sector should appear in dropped_factors.');
        $this->assertNotEmpty($drop['reason']);

        $this->assertCount(1, $spy->calls);
        $this->assertSame('classification failed (no sector in catalog plausible)', $spy->calls[0]['reason']);
    }
}
