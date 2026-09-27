<?php

namespace Tests\Feature;

use App\Models\ClassificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\UpstreamStubs as Stubs;
use Tests\TestCase;

/**
 * Read-only admin reporting over the classification log
 * (contracts/classification-reporting.md): bearer-verified paginated list and
 * per-run detail with the full factor/context payloads.
 */
class ClassificationResultsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function seedRun(): ClassificationResult
    {
        return ClassificationResult::create([
            'inquiry_message' => 'Do you offer annual maintenance contracts?',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone_number' => '+1 555 0100',
            'company_name' => 'Analytical Engines',
            'country_region' => 'United Kingdom',
            'retrieved_context' => ['result_count' => 1, 'results' => [['rank' => 1]]],
            'system_prompt' => 'You are the sales triage assistant for the served scope.',
            'factor_scores' => [['factor' => 'scope_relevance', 'score' => 72, 'weight' => 1.0]],
            'dropped_factors' => [],
            'final_score' => 72.0,
            'classification' => 'medium',
            'reasoning' => 'Matched scope.',
            'web_research_outcome' => 'accept',
            'web_research_reason' => 'Web research completed.',
            'web_research' => [
                'criteria' => [
                    'company' => ['name' => 'Analytical Engines', 'country_region' => 'United Kingdom'],
                    'person' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'company_context' => 'Analytical Engines'],
                ],
                'findings' => [
                    'outcome' => 'completed',
                    'summary' => 'Analytical Engines is an enterprise analytics firm.',
                    'sources' => [['title' => 'About Analytical Engines', 'url' => 'https://example.com/about']],
                    'limitations' => null,
                    'uncertain' => false,
                ],
                'audit' => [
                    'counts' => ['obtained' => 2, 'kept' => 1, 'rejected' => 1],
                    'kept' => [['title' => 'About Analytical Engines', 'url' => 'https://example.com/about']],
                    'rejected' => [['title' => 'Analytical Engines - Aggregator', 'url' => 'https://dir.example/engines']],
                    'filter_outcome' => 'ok',
                    'filter_keep' => [0],
                    'filter_reason' => 'Matches the target.',
                    'rescue_used' => false,
                ],
            ],
        ]);
    }

    public function test_missing_token_returns_401(): void
    {
        $this->getJson('/admin/classification-results')
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Not authenticated.');

        Http::assertNothingSent();
    }

    public function test_invalid_token_returns_401(): void
    {
        Stubs::authVerifyRejected();

        $this->getJson('/admin/classification-results', ['Authorization' => 'Bearer stale-token'])
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Not authenticated.');
    }

    public function test_index_returns_empty_page_on_empty_log(): void
    {
        Stubs::authVerifyOk();

        $this->getJson('/admin/classification-results', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('items', [])
            ->assertJsonPath('total', 0);
    }

    public function test_index_lists_summary_rows_newest_first(): void
    {
        $first = $this->seedRun();
        $second = ClassificationResult::create([
            'inquiry_message' => 'Do you build HubSpot competitors?',
            'first_name' => null,
            'last_name' => null,
            'email' => null,
            'retrieved_context' => ['result_count' => 0, 'results' => []],
            'factor_scores' => [],
            'dropped_factors' => ['scope_relevance'],
            'final_score' => 0.0,
            'classification' => 'low',
            'reasoning' => 'No factors are registered; catalog is empty.',
        ]);

        Stubs::authVerifyOk();

        $this->getJson('/admin/classification-results', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.id', $second->id)
            ->assertJsonPath('items.0.classification', 'low')
            ->assertJsonPath('items.0.final_score', 0)
            ->assertJsonPath('items.0.first_name', null)
            ->assertJsonPath('items.1.id', $first->id)
            ->assertJsonPath('items.1.classification', 'medium')
            ->assertJsonPath('items.1.inquiry_message', 'Do you offer annual maintenance contracts?')
            ->assertJsonPath('items.1.first_name', 'Ada')
            ->assertJsonPath('items.1.last_name', 'Lovelace')
            ->assertJsonPath('items.1.email', 'ada@example.com')
            // Phone/company/country are detail-only; heavy payloads are list-omitted.
            ->assertJsonMissingPath('items.1.phone_number')
            ->assertJsonMissingPath('items.0.factor_scores')
            ->assertJsonMissingPath('items.0.retrieved_context')
            // The list carries the scalar web-research outcome but not the JSON payload.
            ->assertJsonPath('items.1.web_research_outcome', 'accept')
            ->assertJsonMissingPath('items.1.web_research');
    }

    public function test_index_respects_limit_and_offset(): void
    {
        foreach (range(1, 5) as $i) {
            ClassificationResult::create([
                'inquiry_message' => "Inquiry {$i}",
                'retrieved_context' => ['result_count' => 0, 'results' => []],
                'factor_scores' => [],
                'dropped_factors' => [],
                'final_score' => 0.0,
                'classification' => 'low',
                'reasoning' => 'Empty catalog.',
            ]);
        }

        Stubs::authVerifyOk();

        $this->getJson('/admin/classification-results?limit=2', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('limit', 2)
            ->assertJsonPath('total', 5)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.id', 5)
            ->assertJsonPath('items.1.id', 4);

        $this->getJson('/admin/classification-results?limit=2&offset=2', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('items.0.id', 3)
            ->assertJsonPath('items.1.id', 2);
    }

    public function test_index_clamps_limit_to_50(): void
    {
        Stubs::authVerifyOk();

        $this->getJson('/admin/classification-results?limit=500', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('limit', 50);
    }

    public function test_index_returns_503_when_log_unreadable(): void
    {
        Stubs::authVerifyOk();
        $this->seedRun();
        Schema::drop('classification_results');

        $this->getJson('/admin/classification-results', ['Authorization' => 'Bearer token'])
            ->assertStatus(503)
            ->assertJsonPath('detail', 'Classification log unavailable.');
    }

    public function test_show_returns_full_detail(): void
    {
        $run = $this->seedRun();
        Stubs::authVerifyOk();

        $this->getJson("/admin/classification-results/{$run->id}", ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('inquiry_message', 'Do you offer annual maintenance contracts?')
            ->assertJsonPath('first_name', 'Ada')
            ->assertJsonPath('last_name', 'Lovelace')
            ->assertJsonPath('email', 'ada@example.com')
            ->assertJsonPath('phone_number', '+1 555 0100')
            ->assertJsonPath('company_name', 'Analytical Engines')
            ->assertJsonPath('country_region', 'United Kingdom')
            ->assertJsonMissingPath('name')
            ->assertJsonPath('classification', 'medium')
            ->assertJsonPath('factor_scores.0.factor', 'scope_relevance')
            ->assertJsonPath('retrieved_context.result_count', 1)
            ->assertJsonPath('system_prompt', 'You are the sales triage assistant for the served scope.')
            ->assertJsonPath('web_research_outcome', 'accept')
            ->assertJsonPath('web_research_reason', 'Web research completed.')
            ->assertJsonPath('web_research.findings.outcome', 'completed')
            ->assertJsonPath('web_research.findings.summary', 'Analytical Engines is an enterprise analytics firm.')
            ->assertJsonPath('web_research.findings.sources.0.url', 'https://example.com/about')
            ->assertJsonPath('web_research.audit.counts.obtained', 2)
            ->assertJsonPath('web_research.audit.rejected.0.title', 'Analytical Engines - Aggregator');
    }

    public function test_show_unknown_id_returns_404(): void
    {
        Stubs::authVerifyOk();

        $this->getJson('/admin/classification-results/999', ['Authorization' => 'Bearer token'])
            ->assertStatus(404)
            ->assertJsonPath('detail', 'Classification result not found.');
    }

    public function test_stats_counts_the_whole_log_not_just_one_page(): void
    {
        Stubs::authVerifyOk();
        foreach (range(1, 3) as $ignored) {
            $run = $this->seedRun();
            $run->update(['status' => 'succeeded']);
        }

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('by_status.succeeded', 3)
            ->assertJsonPath('by_classification.medium', 3)
            ->assertJsonPath('refusals', 0)
            ->assertJsonPath('scored', 3)
            ->assertJsonPath('no_signal', 0)
            ->assertJsonPath('avg_scored_score', 72);
    }

    public function test_stats_reports_zero_scores_per_verdict(): void
    {
        Stubs::authVerifyOk();

        // 55 zero-score disqualifies, mirroring the live split where gate
        // declines score 0 by construction.
        foreach (range(1, 2) as $ignored) {
            $run = $this->seedRun();
            $run->update(['status' => 'succeeded', 'final_score' => 0.0, 'classification' => 'disqualify']);
        }

        // 48 zero-score lows, mirroring the empty-catalog era where 0 mapped to
        // low rather than disqualify.
        $low = $this->seedRun();
        $low->update(['status' => 'succeeded', 'final_score' => 0.0, 'classification' => 'low']);

        // A scored disqualify: the verdict still shows a zero-score sibling.
        $scoredDisqualify = $this->seedRun();
        $scoredDisqualify->update(['status' => 'succeeded', 'final_score' => 12.0, 'classification' => 'disqualify']);

        // A verdict with no zero-score runs at all is omitted, not reported as 0.
        $scored = $this->seedRun();
        $scored->update(['status' => 'succeeded', 'final_score' => 80.0, 'classification' => 'high']);

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('no_signal', 3)
            ->assertJsonPath('by_classification.disqualify', 3)
            ->assertJsonPath('by_classification.low', 1)
            ->assertJsonPath('by_classification_no_signal', [
                'disqualify' => 2,
                'low' => 1,
            ])
            ->assertJsonPath('scored', 2)
            ->assertJsonPath('avg_scored_score', 46);
    }

    public function test_stats_groups_rows_with_no_status_under_unknown(): void
    {
        Stubs::authVerifyOk();
        $this->seedRun();

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('by_status.unknown', 1);
    }

    public function test_stats_separates_lifecycle_states_and_refusals(): void
    {
        Stubs::authVerifyOk();
        $succeeded = $this->seedRun();
        $succeeded->update(['status' => 'succeeded']);

        $queued = $this->seedRun();
        $queued->update(['status' => 'queued', 'classification' => null, 'final_score' => null]);

        $failed = $this->seedRun();
        $failed->update([
            'status' => 'failed',
            'classification' => 'disqualify',
            'final_score' => 10.0,
            'refusal' => 'We are not able to help with this inquiry.',
        ]);

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('by_status.succeeded', 1)
            ->assertJsonPath('by_status.queued', 1)
            ->assertJsonPath('by_status.failed', 1)
            ->assertJsonPath('by_classification.medium', 1)
            ->assertJsonPath('by_classification.disqualify', 1)
            ->assertJsonPath('refusals', 1)
            // The queued row has a NULL score (not yet scored), so it counts
            // toward neither bucket; (72 + 10) / 2 = 41.
            ->assertJsonPath('scored', 2)
            ->assertJsonPath('no_signal', 0)
            ->assertJsonPath('avg_scored_score', 41);
    }

    public function test_stats_omits_rows_that_have_no_classification_yet(): void
    {
        Stubs::authVerifyOk();
        $done = $this->seedRun();
        $done->update(['status' => 'succeeded']);

        $pending = $this->seedRun();
        $pending->update(['status' => 'scoring', 'classification' => null, 'final_score' => null]);

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('by_status.scoring', 1)
            ->assertJsonPath('by_classification', ['medium' => 1]);
    }

    public function test_stats_rounds_a_fractional_average_to_two_places(): void
    {
        Stubs::authVerifyOk();
        foreach ([72.0, 10.0, 33.0] as $score) {
            $run = $this->seedRun();
            $run->update(['status' => 'succeeded', 'final_score' => $score]);
        }

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            // (72 + 10 + 33) / 3 = 38.3333…
            ->assertJsonPath('avg_scored_score', 38.33);
    }

    public function test_stats_keeps_zero_scores_out_of_the_average(): void
    {
        Stubs::authVerifyOk();
        foreach ([72.0, 10.0, 0.0, 0.0] as $score) {
            $run = $this->seedRun();
            $run->update(['status' => 'succeeded', 'final_score' => $score]);
        }

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('total', 4)
            // A score of 0 is an honest "no signal", not a bad lead, so it is
            // counted separately and excluded from the mean: (72 + 10) / 2 = 41.
            ->assertJsonPath('scored', 2)
            ->assertJsonPath('no_signal', 2)
            ->assertJsonPath('avg_scored_score', 41);
    }

    public function test_stats_reports_no_average_when_every_run_scored_zero(): void
    {
        Stubs::authVerifyOk();
        foreach (range(1, 3) as $ignored) {
            $run = $this->seedRun();
            $run->update(['status' => 'succeeded', 'final_score' => 0.0]);
        }

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('scored', 0)
            ->assertJsonPath('no_signal', 3)
            ->assertJsonPath('avg_scored_score', null);
    }

    public function test_stats_splits_kept_runs_by_whether_they_scored_anything(): void
    {
        Stubs::authVerifyOk();

        // kept and scored
        foreach ([72.0, 55.0, 30.0] as $score) {
            $run = $this->seedRun();
            $run->update(['status' => 'succeeded', 'final_score' => $score, 'classification' => 'medium']);
        }

        // kept but no signal: the empty-catalog rule records 0 as `low`
        foreach (range(1, 2) as $ignored) {
            $run = $this->seedRun();
            $run->update(['status' => 'succeeded', 'final_score' => 0.0, 'classification' => 'low']);
        }

        // disqualified however it got there: scored below 30, or a gate decline
        $run = $this->seedRun();
        $run->update(['status' => 'succeeded', 'final_score' => 22.0, 'classification' => 'disqualify']);

        $run = $this->seedRun();
        $run->update([
            'status' => 'succeeded',
            'final_score' => 0.0,
            'classification' => 'disqualify',
            'refusal' => 'That company is outside the scope we serve.',
        ]);

        // a succeeded run with no verdict yet must not slip between the buckets
        $run = $this->seedRun();
        $run->update(['status' => 'succeeded', 'final_score' => 0.0, 'classification' => null]);

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('total', 8)
            // The verdict wins over the score: a disqualified run is disqualified
            // however it scored, and a kept run is split only on whether the
            // factor produced anything.
            ->assertJsonPath('by_classification.disqualify', 2)
            ->assertJsonPath('scored_kept', 3)
            // The two 0-score `low` runs plus the null-verdict run, which counts
            // as kept so the partition cannot leak a row.
            ->assertJsonPath('no_signal_kept', 3);
    }

    public function test_stats_kept_buckets_exclude_failed_runs_so_they_still_count_as_failed(): void
    {
        Stubs::authVerifyOk();

        $run = $this->seedRun();
        $run->update(['status' => 'failed', 'final_score' => 72.0, 'classification' => 'medium']);

        $run = $this->seedRun();
        $run->update(['status' => 'failed', 'final_score' => 0.0, 'classification' => 'low']);

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('by_status.failed', 2)
            // A crashed run is never "kept", even when it had already scored, so
            // it stays in the failed bucket and is counted exactly once.
            ->assertJsonPath('scored_kept', 0)
            ->assertJsonPath('no_signal_kept', 0);
    }

    public function test_stats_buckets_partition_every_run_exactly_once(): void
    {
        Stubs::authVerifyOk();

        $scenarios = [
            ['status' => 'succeeded', 'final_score' => 72.0, 'classification' => 'high'],
            ['status' => 'succeeded', 'final_score' => 0.0, 'classification' => 'low'],
            ['status' => 'succeeded', 'final_score' => 22.0, 'classification' => 'disqualify'],
            ['status' => 'succeeded', 'final_score' => 0.0, 'classification' => null],
            ['status' => 'failed', 'final_score' => 0.0, 'classification' => 'low'],
            ['status' => 'queued', 'final_score' => null, 'classification' => null],
            ['status' => 'scoring', 'final_score' => null, 'classification' => null],
        ];

        foreach ($scenarios as $scenario) {
            $run = $this->seedRun();
            $run->update($scenario);
        }

        $stats = $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->json();

        $inFlight = ['queued', 'processing', 'researching', 'scope_check', 'scoring'];
        $inFlightCount = 0;
        foreach ($inFlight as $status) {
            $inFlightCount += (int) ($stats['by_status'][$status] ?? 0);
        }

        $partitioned = $stats['scored_kept']
            + $stats['no_signal_kept']
            + ($stats['by_classification']['disqualify'] ?? 0)
            + ($stats['by_status']['failed'] ?? 0)
            + $inFlightCount;

        // The dashboard renders one card per bucket, so a run must never fall
        // between them or be counted twice. This is the guarantee the row's
        // numbers add up to the total.
        $this->assertSame($stats['total'], $partitioned);
    }

    public function test_stats_on_empty_log_returns_zeroes(): void
    {
        Stubs::authVerifyOk();

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('by_status', [])
            ->assertJsonPath('by_classification', [])
            ->assertJsonPath('by_classification_no_signal', [])
            ->assertJsonPath('refusals', 0)
            ->assertJsonPath('scored', 0)
            ->assertJsonPath('no_signal', 0)
            ->assertJsonPath('avg_scored_score', null)
            ->assertJsonPath('scored_kept', 0)
            ->assertJsonPath('no_signal_kept', 0);
    }

    public function test_stats_requires_upstream_token(): void
    {
        $this->getJson('/admin/classification-results/stats')
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Not authenticated.');

        Http::assertNothingSent();
    }

    public function test_stats_returns_503_when_log_unreadable(): void
    {
        Stubs::authVerifyOk();
        $this->seedRun();
        Schema::drop('classification_results');

        $this->getJson('/admin/classification-results/stats', ['Authorization' => 'Bearer token'])
            ->assertStatus(503)
            ->assertJsonPath('detail', 'Classification log unavailable.');
    }
}
