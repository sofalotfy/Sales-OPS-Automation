<?php

namespace Tests\Feature;

use App\Support\UpstreamSession;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs;
use Tests\TestCase;

class DashboardHomeTest extends TestCase
{
    public function test_dashboard_home_requires_authentication(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login.show'));
    }

    public function test_dashboard_home_renders_run_stats_and_recent_runs(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDashboardRuns(
            UpstreamStubs::classificationStats(
                total: 139,
                byStatus: ['succeeded' => 136, 'failed' => 2, 'researching' => 1],
                byClassification: ['low' => 62, 'disqualify' => 56, 'medium' => 14, 'high' => 7],
                refusals: 4,
                scored: 36,
                noSignal: 103,
                avgScoredScore: 55.77,
                byClassificationNoSignal: ['disqualify' => 55, 'low' => 48],
                // A coherent partition: 35 + 45 + 56 disqualified + 2 failed +
                // 1 in flight = 139. The two failed and the in-flight run are
                // not "kept", so they come out of the kept buckets.
                scoredKept: 35,
                noSignalKept: 45,
            ),
            [
                UpstreamStubs::classificationResult(id: 9, classification: 'high', score: 88),
                UpstreamStubs::classificationResult(id: 8, classification: 'low', score: 12),
            ],
        );

        $this->get(route('dashboard'))
            ->assertOk()
            // `>Runs</p>` is the stat card's own label markup, so this cannot be
            // satisfied by "Recent runs" or "View all runs".
            ->assertSee('<p class="text-sm text-slate-500">Runs</p>', false)
            ->assertSee('<p class="mt-1 text-2xl font-semibold text-slate-900">139</p>', false)
            ->assertSee('Succeeded')
            ->assertSee('Disqualified')
            ->assertSee('Failed')
            ->assertSee('In flight')
            // Each card's value is pinned to its own colour so a number cannot
            // satisfy the wrong card. "Succeeded" is the scored kept runs and
            // "Failed" the kept runs that found no signal, so neither is the
            // 136 succeeded runs reported by status.
            ->assertSee('<p class="mt-1 text-2xl font-semibold text-emerald-600">35</p>', false)
            ->assertSee('<p class="mt-1 text-2xl font-semibold text-amber-600">56</p>', false)
            ->assertSee('<p class="mt-1 text-2xl font-semibold text-red-600">45</p>', false)
            // Only the in-flight card carries the sky-600 value styling, so this
            // pins `researching: 1` to that card rather than any other number.
            ->assertSee('<p class="mt-1 text-2xl font-semibold text-sky-600">1</p>', false)
            // "Failed" means no usable signal, so the card has to say so rather
            // than let a reader assume it counts crashed runs.
            ->assertSee('no usable signal')
            ->assertSee('Usable signal')
            ->assertSee('Avg score (scored runs)')
            ->assertSee('55.77')
            // The zero-signal count has to reach the page, otherwise the
            // headline score silently hides the runs it excludes.
            ->assertSee('103 scored 0')
            ->assertSee('Excludes 103 zero-signal runs')
            ->assertSee('Refusals')
            ->assertSee('Classification mix')
            // The mix must show which verdicts are the zero-score ones, or it
            // reads as contradicting the "103 scored 0" card above it.
            ->assertSee('55 scored 0')
            ->assertSee('48 scored 0')
            // `high` has no zero-score siblings, so it must not claim any.
            ->assertDontSee('7 scored 0')
            ->assertSee('Recent runs');
    }

    public function test_dashboard_home_renders_failed_runs_under_needs_attention(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDashboardRuns(
            UpstreamStubs::classificationStats(
                total: 3,
                byStatus: ['succeeded' => 1, 'failed' => 2],
                byClassification: ['medium' => 1],
            ),
            [
                UpstreamStubs::classificationResult(id: 3, status: 'failed', error: 'AI provider unreachable.'),
            ],
        );

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('AI provider unreachable.')
            // One failed run sits outside the newest page, so the page must admit
            // the count is incomplete rather than implying 1 of 1.
            ->assertSee('older failed run');
    }

    public function test_dashboard_home_reports_no_failures_when_the_log_is_clean(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDashboardRuns(
            UpstreamStubs::classificationStats(),
            [UpstreamStubs::classificationResult(id: 1)],
        );

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No runs have failed.');
    }

    public function test_dashboard_home_no_longer_shows_documents(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDashboardRuns(
            UpstreamStubs::classificationStats(),
            [UpstreamStubs::classificationResult(id: 1)],
        );

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Total documents')
            ->assertDontSee('Upload document')
            ->assertDontSee('Recent documents');
    }

    public function test_dashboard_home_401_forces_relogin(): void
    {
        $this->signIn();
        UpstreamStubs::fakeUpstreamUnauthorized();

        $this->get(route('dashboard'))->assertRedirect(route('login.show'));
    }

    public function test_dashboard_home_surfaces_service_error(): void
    {
        $this->signIn();
        Http::fake([
            UpstreamStubs::inquiryUrl('/admin/classification-results*') => Http::response(
                ['detail' => 'service down'],
                503,
            ),
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('service down');
    }

    public function test_dashboard_home_degrades_when_only_the_run_list_fails(): void
    {
        $this->signIn();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/classification-results/stats')) {
                return Http::response(UpstreamStubs::classificationStats(), 200);
            }

            return Http::response(['detail' => 'log unavailable'], 503);
        });

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('139')
            ->assertSee('The run log is unavailable');
    }

    public function test_dashboard_home_degrades_when_only_the_stats_fail(): void
    {
        $this->signIn();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/classification-results/stats')) {
                return Http::response(['detail' => 'stats unavailable'], 503);
            }

            return Http::response([
                'items' => [UpstreamStubs::classificationResult(id: 7, classification: 'medium')],
                'total' => 7,
                'limit' => 20,
                'offset' => 0,
            ], 200);
        });

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('stats unavailable')
            // The stat cards are suppressed, but the recent-runs table still renders.
            ->assertSee('Recent runs')
            ->assertSee('Medium')
            ->assertDontSee('<p class="text-sm text-slate-500">Runs</p>', false);
    }

    public function test_login_redirects_to_dashboard_home(): void
    {
        UpstreamStubs::fakeLoginOk();

        $this->post(route('login'), ['username' => 'admin', 'password' => 's3cret-pass'])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(UpstreamSession::authenticated());
    }
}
