<?php

namespace Tests\Feature;

use App\Models\ClassificationResult;
use App\WebResearch\ResearchAgent;
use App\WebResearch\ResearchOutcome;
use App\WebResearch\ResearchResult;
use App\WebResearch\WebResearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\UpstreamStubs as Stubs;
use Tests\TestCase;

/**
 * Web-research middleware feature tests (feature 010 updated).
 *
 * The step is DISABLED globally in phpunit.xml so the pre-existing contract
 * tests keep their exact behavior; every test here re-enables it. A fake
 * provider and a fake research agent are bound per-case so the no-op default
 * (and no real search engine / AI / page fetch) is never relied on.
 */
class WebResearchMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['web_research.enabled' => true]);
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

    private function payload(string $message): array
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

    private function fakeClassificationUpstreams(): void
    {
        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
    }

    // ---------------------------------------------------------------- accept

    public function test_accept_carries_agent_findings_in_context_and_persists_columns(): void
    {
        $this->bindProvider(fn (array $criteria) => [
            'company' => ['results' => [['title' => 'Example Corp', 'url' => 'https://example.com/about', 'snippet' => 'Fintech']]],
            'person' => ['results' => []],
        ]);
        $this->bindAgent(new ResearchResult(
            ResearchOutcome::Completed,
            'Example Corp is a UK fintech; Jane Doe leads engineering there.',
            [['title' => 'About Example Corp', 'url' => 'https://example.com/about']],
            null,
            audit: [
                'counts' => ['obtained' => 2, 'kept' => 1, 'rejected' => 1],
                'kept' => [['title' => 'About Example Corp', 'url' => 'https://example.com/about']],
                'rejected' => [['title' => 'Example Corp Ltd - Aggregator', 'url' => 'https://dir.example/corp']],
                'filter_outcome' => 'kept',
                'filter_keep' => [0],
                'filter_reason' => '',
                'rescue_used' => false,
            ],
        ));

        $this->fakeClassificationUpstreams();

        $response = $this->postJson('/inquiry/triage', $this->payload('Do you build enterprise web applications?'));

        $response->assertOk()
            ->assertJsonPath('context.web_research.outcome', 'accept')
            ->assertJsonPath('context.web_research.findings.outcome', 'completed')
            ->assertJsonPath('context.web_research.findings.summary', 'Example Corp is a UK fintech; Jane Doe leads engineering there.')
            ->assertJsonPath('context.web_research.findings.sources.0.url', 'https://example.com/about')
            ->assertJsonPath('context.web_research.criteria.company.name', 'Example Corp')
            ->assertJsonPath('context.web_research.criteria.person.first_name', 'Jane')
            // The candidate audit is an operational detail: persisted for the
            // dashboard, but not part of the public triage response.
            ->assertJsonMissingPath('context.web_research.audit')
            // Raw candidate result lists are gone.
            ->assertJsonMissingPath('context.web_research.findings.company')
            ->assertJsonMissingPath('context.web_research.findings.person');

        $row = ClassificationResult::orderByDesc('id')->first();
        $this->assertSame('accept', $row->web_research_outcome);
        $this->assertSame('completed', $row->web_research['findings']['outcome']);
        $this->assertSame('Example Corp', $row->web_research['criteria']['company']['name']);
        $this->assertSame(1, $row->web_research['audit']['counts']['rejected']);
        $this->assertSame('Example Corp Ltd - Aggregator', $row->web_research['audit']['rejected'][0]['title']);
        $this->assertArrayNotHasKey('company', $row->web_research['findings']);
    }

    // ---------------------------------------------------------------- decline

    public function test_decline_short_circuits_with_refusal_and_persists_record(): void
    {
        $this->bindProvider(fn (array $criteria) => [
            'company' => ['is_defunct' => true],
            'decline' => [
                'reason' => 'Research shows the company is defunct and no longer operating.',
                'refusal' => 'Thank you for reaching out, but we are not able to help with this inquiry.',
            ],
        ]);
        $this->bindAgent(ResearchResult::notFound('not reached'));

        $response = $this->postJson('/inquiry/triage', $this->payload('Do you build enterprise web applications?'));

        $response->assertOk()
            ->assertJsonPath('classification', 'disqualify')
            ->assertJsonPath('score', 0)
            ->assertJsonPath('factor_scores', [])
            ->assertJsonPath('dropped_factors', [])
            ->assertJsonPath('reply', 'Thank you for reaching out, but we are not able to help with this inquiry.')
            ->assertJsonPath('reasoning', 'Research shows the company is defunct and no longer operating.')
            ->assertJsonPath('context.web_research.outcome', 'decline')
            ->assertJsonPath('context.web_research.findings.company.is_defunct', true);

        $this->assertSame(1, ClassificationResult::query()->count());
        $this->assertDatabaseHas('classification_results', [
            'classification' => 'disqualify',
            'web_research_outcome' => 'decline',
            'web_research_reason' => 'Research shows the company is defunct and no longer operating.',
        ]);

        $row = ClassificationResult::query()->first();
        $this->assertSame('Example Corp', $row->web_research['criteria']['company']['name']);
        $this->assertTrue($row->web_research['findings']['company']['is_defunct']);
    }

    // --------------------------------------------------------------- honesty

    public function test_not_found_is_an_accept_with_an_explicit_statement(): void
    {
        $this->bindProvider(fn () => ['company' => ['results' => []], 'person' => ['results' => []]]);
        $this->bindAgent(ResearchResult::notFound('No public information about Example Corp / Jane Doe could be found to establish a profile.'));
        $this->fakeClassificationUpstreams();

        $this->postJson('/inquiry/triage', $this->payload('Do you build enterprise web applications?'))
            ->assertOk()
            ->assertJsonPath('context.web_research.outcome', 'accept')
            ->assertJsonPath('context.web_research.findings.outcome', 'not_found')
            ->assertJsonPath('context.web_research.findings.summary', 'No public information about Example Corp / Jane Doe could be found to establish a profile.');
    }

    public function test_ambiguous_is_an_accept_with_an_explicit_statement(): void
    {
        $this->bindProvider(fn () => [
            'company' => ['results' => [['title' => 'A', 'url' => 'https://a.example', 'snippet' => '']]],
            'person' => ['results' => []],
        ]);
        $this->bindAgent(ResearchResult::ambiguous('The name Example Corp matches multiple distinct entities and could not be resolved to a single one.'));
        $this->fakeClassificationUpstreams();

        $this->postJson('/inquiry/triage', $this->payload('Do you build enterprise web applications?'))
            ->assertOk()
            ->assertJsonPath('context.web_research.outcome', 'accept')
            ->assertJsonPath('context.web_research.findings.outcome', 'ambiguous');
    }

    // ----------------------------------------------------------- indeterminate

    public function test_provider_failure_fails_open_to_classification(): void
    {
        $this->bindProvider(fn () => throw new \RuntimeException('search provider down'));
        $this->bindAgent(ResearchResult::notFound('not reached'));

        $this->fakeClassificationUpstreams();

        $this->postJson('/inquiry/triage', $this->payload('Do you build enterprise web applications?'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('context.web_research.outcome', 'indeterminate')
            ->assertJsonPath('context.web_research.findings', []);

        $row = ClassificationResult::orderByDesc('id')->first();
        $this->assertSame('indeterminate', $row->web_research_outcome);
    }

    public function test_agent_failure_fails_open_to_classification(): void
    {
        $this->bindProvider(fn () => [
            'company' => ['results' => [['title' => 'Example Corp', 'url' => 'https://example.com', 'snippet' => '']]],
            'person' => ['results' => []],
        ]);
        $this->bindAgent(ResearchResult::indeterminate('The research filter was unavailable.'));

        $this->fakeClassificationUpstreams();

        $this->postJson('/inquiry/triage', $this->payload('Do you build enterprise web applications?'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('context.web_research.outcome', 'indeterminate')
            ->assertJsonPath('context.web_research.findings', []);

        $row = ClassificationResult::orderByDesc('id')->first();
        $this->assertSame('indeterminate', $row->web_research_outcome);
        $this->assertSame([], $row->web_research['findings']);
    }

    public function test_default_provider_fails_open_without_api_key(): void
    {
        config(['services.tavily.key' => '']);
        $this->bindAgent(ResearchResult::notFound('not reached'));

        $this->fakeClassificationUpstreams();

        $this->postJson('/inquiry/triage', $this->payload('Do you build enterprise web applications?'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonPath('context.web_research.outcome', 'indeterminate')
            ->assertJsonPath('context.web_research.findings', []);
    }

    // ------------------------------------------------------- invalid payloads

    public function test_non_object_json_body_still_returns_400_before_the_step(): void
    {
        $response = $this->call('POST', '/inquiry/triage', [], [], [], [
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '12345');

        $response->assertStatus(400)
            ->assertJsonPath('detail', 'A JSON object is required.');
    }

    public function test_blank_message_still_returns_422_before_the_step(): void
    {
        $response = $this->postJson('/inquiry/triage', $this->payload('   '));

        $response->assertStatus(422)
            ->assertJsonPath('detail', 'The message must not be blank.');
    }

    // ----------------------------------------------------------- kill switch

    public function test_disabled_bypasses_web_research_without_marker(): void
    {
        config(['web_research.enabled' => false]);

        $this->fakeClassificationUpstreams();

        $this->postJson('/inquiry/triage', $this->payload('Do you build enterprise web applications?'))
            ->assertOk()
            ->assertJsonPath('classification', 'low')
            ->assertJsonMissingPath('context.web_research');

        $row = ClassificationResult::orderByDesc('id')->first();
        $this->assertNull($row->web_research_outcome);
        $this->assertNull($row->web_research);
    }
}
