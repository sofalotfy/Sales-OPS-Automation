<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs;
use Tests\TestCase;

/**
 * Inquiry-classification tab: the factor-weights editor plus the classification
 * log (list + per-run detail), all served over inquiry-handler's bearer-verified
 * admin APIs. HTTP-only — the dashboard never touches the weights/log store
 * directly (SC-006).
 */
class ClassificationTest extends TestCase
{
    private function stubIndex(callable $weights, callable $results): void
    {
        Http::fake([
            UpstreamStubs::inquiryUrl('/admin/factor-settings') => $weights(),
            UpstreamStubs::inquiryUrl('/admin/classification-results*') => $results(),
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('classification.index'))->assertRedirect(route('login.show'));
    }

    public function test_index_renders_weights_and_results(): void
    {
        $this->signIn();
        $this->stubIndex(
            fn () => Http::response([
                'factors' => [UpstreamStubs::factor(name: 'scope_relevance', weight: 0.5)],
                'stored' => ['scope_relevance' => 0.5],
            ], 200),
            fn () => Http::response([
                'items' => [
                    UpstreamStubs::classificationResult(id: 2, classification: 'medium', score: 72),
                    UpstreamStubs::classificationResult(id: 1),
                ],
                'total' => 2,
                'limit' => 20,
                'offset' => 0,
            ], 200),
        );

        $this->get(route('classification.index'))
            ->assertOk()
            ->assertSee('Inquiry classification')
            ->assertSee('scope_relevance')
            ->assertSee('Save weights')
            ->assertSee('Recent classifications')
            ->assertSee('Do you offer annual maintenance contracts?')
            ->assertSee('2 total');
    }

    public function test_index_empty_state(): void
    {
        $this->signIn();
        $this->stubIndex(
            fn () => Http::response(['factors' => [], 'stored' => []], 200),
            fn () => Http::response(['items' => [], 'total' => 0, 'limit' => 20, 'offset' => 0], 200),
        );

        $this->get(route('classification.index'))
            ->assertOk()
            ->assertSee('No factors are registered')
            ->assertSee('No inquiries classified yet');
    }

    public function test_index_surfaces_weights_failure_but_still_renders_results(): void
    {
        $this->signIn();
        $this->stubIndex(
            fn () => Http::response(['detail' => 'Settings store unavailable.'], 503),
            fn () => Http::response([
                'items' => [UpstreamStubs::classificationResult(id: 1)],
                'total' => 1,
                'limit' => 20,
                'offset' => 0,
            ], 200),
        );

        $this->get(route('classification.index'))
            ->assertOk()
            ->assertSee('Settings store unavailable.')
            ->assertSee('Do you offer annual maintenance contracts?');
    }

    public function test_index_surfaces_results_failure_but_still_renders_weights(): void
    {
        $this->signIn();
        $this->stubIndex(
            fn () => Http::response([
                'factors' => [UpstreamStubs::factor(name: 'scope_relevance', weight: 0.5)],
                'stored' => [],
            ], 200),
            fn () => Http::response(['detail' => 'Classification log unavailable.'], 503),
        );

        $this->get(route('classification.index'))
            ->assertOk()
            ->assertSee('Classification log unavailable.')
            ->assertSee('scope_relevance');
    }

    public function test_index_upstream_401_forces_relogin(): void
    {
        $this->signIn();
        UpstreamStubs::fakeUpstreamUnauthorized();

        $this->get(route('classification.index'))->assertRedirect(route('login.show'));
    }

    public function test_update_sends_weights_and_flashes_status(): void
    {
        $this->signIn();
        UpstreamStubs::fakeFactorSettingsUpdate([
            UpstreamStubs::factor(name: 'scope_relevance', weight: 0.75, source: 'stored'),
        ]);

        $this->from(route('classification.index'))
            ->put(route('classification.update'), [
                'weights' => ['scope_relevance' => 0.75],
            ])
            ->assertRedirect(route('classification.index'))
            ->assertSessionHas('status', 'Factor weights saved.');

        Http::assertSent(fn ($request) => $request->url() === UpstreamStubs::inquiryUrl('/admin/factor-settings')
            && $request->method() === 'PUT'
            && data_get($request->data(), 'weights.scope_relevance') === 0.75);
    }

    public function test_update_surfaces_rejection_inline(): void
    {
        $this->signIn();
        UpstreamStubs::fakeFactorSettingsRejected();

        $this->from(route('classification.index'))
            ->put(route('classification.update'), [
                'weights' => ['nope' => 0.5],
            ])
            ->assertRedirect(route('classification.index'))
            ->assertSessionHasErrors('weights');
    }

    public function test_update_upstream_401_forces_relogin(): void
    {
        $this->signIn();
        UpstreamStubs::fakeUpstreamUnauthorized();

        $this->put(route('classification.update'), ['weights' => ['scope_relevance' => 0.5]])
            ->assertRedirect(route('login.show'));
    }

    public function test_show_requires_authentication(): void
    {
        $this->get(route('classification.show', 1))->assertRedirect(route('login.show'));
    }

    public function test_show_renders_full_detail(): void
    {
        $this->signIn();
        UpstreamStubs::fakeClassificationResult(UpstreamStubs::classificationDetail(id: 1));

        $this->get(route('classification.show', 1))
            ->assertOk()
            ->assertSee('Classification #1')
            ->assertSee('Ada Lovelace')
            ->assertSee('scope_relevance')
            ->assertSee('Matched scope.')
            ->assertSee('System prompt')
            ->assertSee('SERVED SCOPE')
            ->assertSee('Web research')
            ->assertSee('Web research completed.')
            ->assertSee('Research summary')
            ->assertSee('Completed')
            ->assertSee('Example Corp is an enterprise fintech;')
            ->assertSee('Example Corp — About')
            ->assertSee('https://example.com/about')
            ->assertSee('Ada Lovelace on LinkedIn');
    }

    public function test_show_renders_candidate_filter_audit(): void
    {
        $this->signIn();
        UpstreamStubs::fakeClassificationResult(UpstreamStubs::classificationDetail(id: 1));

        $this->get(route('classification.show', 1))
            ->assertOk()
            ->assertSee('Candidate filter audit')
            ->assertSee('3 obtained')
            ->assertSee('2 kept by filter')
            ->assertSee('1 rejected')
            ->assertSee('Rejected candidates (1)')
            ->assertSee('10 Best Companies to Work For')
            ->assertSee('https://aggregator.example/list');
    }

    public function test_show_hides_candidate_filter_audit_when_absent(): void
    {
        $this->signIn();
        $detail = UpstreamStubs::classificationDetail(id: 1);
        unset($detail['web_research']['audit']);
        UpstreamStubs::fakeClassificationResult($detail);

        $this->get(route('classification.show', 1))
            ->assertOk()
            ->assertSee('Web research')
            ->assertDontSee('Candidate filter audit');
    }

    public function test_show_renders_summarized_findings_with_sources_and_limitations(): void
    {
        $this->signIn();
        $detail = UpstreamStubs::classificationDetail(id: 1);
        $detail['web_research']['findings'] = [
            'outcome' => 'partial',
            'summary' => 'Example Corp is an enterprise automation vendor.',
            'sources' => [['title' => 'Example Corp — About', 'url' => 'https://example.com/about']],
            'limitations' => 'One source could not be retrieved.',
        ];
        UpstreamStubs::fakeClassificationResult($detail);

        $this->get(route('classification.show', 1))
            ->assertOk()
            ->assertSee('Research summary')
            ->assertSee('Partial')
            ->assertSee('Example Corp is an enterprise automation vendor.')
            ->assertSee('One source could not be retrieved.')
            ->assertSee('Sources')
            ->assertSee('Example Corp — About')
            ->assertSee('https://example.com/about');
    }

    public function test_show_renders_uncertainty_warning_when_rescue_was_used(): void
    {
        $this->signIn();
        $detail = UpstreamStubs::classificationDetail(id: 1);
        $detail['web_research']['findings'] = [
            'outcome' => 'partial',
            'summary' => 'Best-effort profile of Example Corp.',
            'sources' => [['title' => 'Example Corp — About', 'url' => 'https://example.com/about']],
            'limitations' => 'Best-effort research: some details may be confounded.',
            'uncertain' => true,
        ];
        UpstreamStubs::fakeClassificationResult($detail);

        $this->get(route('classification.show', 1))
            ->assertOk()
            ->assertSee('Best-effort research')
            ->assertSee('some details may be inaccurate or incomplete. Verify before relying');
    }

    public function test_show_renders_not_found_summary_without_sources(): void
    {
        $this->signIn();
        $detail = UpstreamStubs::classificationDetail(id: 1);
        $detail['web_research']['findings'] = [
            'outcome' => 'not_found',
            'summary' => 'No public information about Example Corp / Ada Lovelace could be found.',
            'sources' => [],
            'limitations' => null,
        ];
        UpstreamStubs::fakeClassificationResult($detail);

        $this->get(route('classification.show', 1))
            ->assertOk()
            ->assertSee('Not found')
            ->assertSee('No public information about Example Corp / Ada Lovelace could be found.')
            ->assertSee('No sources were retained for this summary.');
    }

    public function test_show_renders_web_research_outcome_and_criteria(): void
    {
        $this->signIn();
        $detail = UpstreamStubs::classificationDetail(id: 1);
        $detail['web_research_outcome'] = 'decline';
        $detail['web_research_reason'] = 'Company research shows the prospect is defunct.';
        $detail['web_research']['findings'] = [
            'company' => [
                'query' => 'Example Corp United Kingdom company',
                'summary' => null,
                'found' => true,
                'results' => [
                    ['title' => 'Example Corp — About', 'url' => 'https://example.com/about', 'snippet' => 'No longer operating.', 'score' => 0.9, 'published_date' => null],
                ],
            ],
        ];
        UpstreamStubs::fakeClassificationResult($detail);

        $this->get(route('classification.show', 1))
            ->assertOk()
            ->assertSee('Web research')
            ->assertSee('decline')
            ->assertSee('Company research shows the prospect is defunct.')
            ->assertSee('Company findings')
            ->assertSee('Example Corp — About')
            ->assertSee('Example Corp')
            ->assertSee('Ada Lovelace');
    }

    public function test_show_hides_web_research_section_when_absent(): void
    {
        $this->signIn();
        $detail = UpstreamStubs::classificationDetail(id: 2);
        unset($detail['web_research'], $detail['web_research_outcome'], $detail['web_research_reason']);
        UpstreamStubs::fakeClassificationResult($detail);

        $this->get(route('classification.show', 2))
            ->assertOk()
            ->assertDontSee('Web research')
            ->assertSee('System prompt');
    }

    public function test_show_missing_returns_error_view(): void
    {
        $this->signIn();
        UpstreamStubs::fakeClassificationResultMissing();

        $this->get(route('classification.show', 999))
            ->assertOk()
            ->assertSee('Classification result not found.');
    }

    public function test_show_upstream_401_forces_relogin(): void
    {
        $this->signIn();
        UpstreamStubs::fakeUpstreamUnauthorized();

        $this->get(route('classification.show', 1))->assertRedirect(route('login.show'));
    }
}
