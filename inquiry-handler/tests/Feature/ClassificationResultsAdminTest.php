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
}
