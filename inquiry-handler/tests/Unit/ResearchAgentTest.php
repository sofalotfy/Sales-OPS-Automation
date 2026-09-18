<?php

namespace Tests\Unit;

use App\Services\AiCallingService;
use App\WebResearch\PageFetcher;
use App\WebResearch\ResearchAgent;
use App\WebResearch\ResearchOutcome;
use Mockery;
use Tests\TestCase;

/**
 * AI research agent unit tests (feature 010).
 *
 * The AiCallingService is mocked so the filter/summarize turns are canned and
 * the PageFetcher is replaced with an in-memory map, keeping the whole pipeline
 * deterministic without live providers.
 */
class ResearchAgentTest extends TestCase
{
    /** @var array<int, array{system: string, user: string, model: string}> */
    private array $aiCalls = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function makeAgent(array $aiResponses, array $pages = []): ResearchAgent
    {
        $queue = $aiResponses;

        $ai = Mockery::mock(AiCallingService::class);
        $ai->shouldReceive('complete')->andReturnUsing(function (string $system, string $user, ?string $model = null) use (&$queue) {
            $this->aiCalls[] = ['system' => $system, 'user' => $user, 'model' => $model ?? ''];

            return array_shift($queue);
        });

        return new ResearchAgent($ai, $this->fakeFetcher($pages));
    }

    private function fakeFetcher(array $pages): PageFetcher
    {
        return new class($pages) extends PageFetcher
        {
            public function __construct(private array $pages) {}

            public function fetch(string $url): ?array
            {
                return $this->pages[$url] ?? null;
            }
        };
    }

    private function criteria(array $overrides = []): array
    {
        return array_merge([
            'company' => ['name' => 'Example Corp', 'country_region' => 'United Kingdom'],
            'person' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'company_context' => 'Example Corp'],
        ], $overrides);
    }

    private function payload(array $company = [], array $person = []): array
    {
        return [
            'company' => ['query' => 'q', 'summary' => null, 'found' => $company !== [], 'results' => $company],
            'person' => ['query' => 'q', 'summary' => null, 'found' => $person !== [], 'results' => $person],
        ];
    }

    private function candidate(string $title, string $url, string $snippet = 'snippet'): array
    {
        return ['title' => $title, 'url' => $url, 'snippet' => $snippet, 'score' => 0.9, 'published_date' => null];
    }

    private function page(string $title, string $url, string $text = 'Fetched body'): array
    {
        return ['title' => $title, 'url' => $url, 'text' => $text];
    }

    private function filterOk(array $keep, string $outcome = 'ok'): array
    {
        return ['outcome' => $outcome, 'keep' => $keep, 'reason' => 'reason'];
    }

    private function summaryOk(string $summary, array $sources = [], ?string $limitations = null): array
    {
        return ['summary' => $summary, 'sources' => $sources, 'limitations' => $limitations];
    }

    public function test_filters_then_fetches_then_summarizes_the_kept_source(): void
    {
        $url = 'https://example.com/about';
        $agent = $this->makeAgent(
            [$this->filterOk([1]), $this->summaryOk('Example Corp is a UK fintech.')],
            [$url => $this->page('About Example Corp', $url, 'Example Corp builds payments software.')],
        );

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Example Corp — About', $url),
            $this->candidate('10 Best Companies', 'https://aggregator.example/list'),
        ]));

        $this->assertSame(ResearchOutcome::Completed, $result->outcome);
        $this->assertSame('Example Corp is a UK fintech.', $result->summary);
        $this->assertSame([['title' => 'About Example Corp', 'url' => $url]], $result->sources);
        $this->assertSame([
            'outcome' => 'completed',
            'summary' => 'Example Corp is a UK fintech.',
            'sources' => [['title' => 'About Example Corp', 'url' => $url]],
            'limitations' => null,
            'uncertain' => false,
        ], $result->toFindings());
        $this->assertCount(2, $this->aiCalls);
        $researchModel = (string) config('services.zai.research_model', 'glm-4.7-flash');
        $this->assertSame($researchModel, $this->aiCalls[0]['model']);
        $this->assertSame($researchModel, $this->aiCalls[1]['model']);

        $this->assertSame(['obtained' => 2, 'kept' => 1, 'rejected' => 1], $result->audit['counts']);
        $this->assertSame([['title' => 'Example Corp — About', 'url' => $url]], $result->audit['kept']);
        $this->assertSame([['title' => '10 Best Companies', 'url' => 'https://aggregator.example/list']], $result->audit['rejected']);
        $this->assertSame('ok', $result->audit['filter_outcome']);
        $this->assertSame([1], $result->audit['filter_keep']);
        $this->assertFalse($result->audit['rescue_used']);
    }

    public function test_research_uses_the_configured_research_model(): void
    {
        config(['services.zai.research_model' => 'glm-4.5-flash']);

        $url = 'https://example.com/about';
        $agent = $this->makeAgent(
            [$this->filterOk([1]), $this->summaryOk('Summary.')],
            [$url => $this->page('About', $url)],
        );

        $agent->research($this->criteria(), $this->payload([$this->candidate('Example Corp', $url)]));

        $this->assertCount(2, $this->aiCalls);
        $this->assertSame('glm-4.5-flash', $this->aiCalls[0]['model']);
        $this->assertSame('glm-4.5-flash', $this->aiCalls[1]['model']);
    }

    public function test_filter_prompt_keeps_target_and_candidates_in_the_user_role_only(): void
    {
        $url = 'https://example.com/about';
        $agent = $this->makeAgent(
            [$this->filterOk([1]), $this->summaryOk('Summary.')],
            [$url => $this->page('About', $url)],
        );

        $agent->research($this->criteria(), $this->payload([$this->candidate('Example Corp', $url)]));

        $filter = $this->aiCalls[0];
        $this->assertStringContainsString('research filter', strtolower($filter['system']));
        $this->assertStringNotContainsString('Example Corp', $filter['system']);
        $this->assertStringContainsString('TARGET', $filter['user']);
        $this->assertStringContainsString('Example Corp', $filter['user']);
        $this->assertStringContainsString($url, $filter['user']);
    }

    public function test_not_found_when_filter_reports_not_found_and_skips_summarize(): void
    {
        $agent = $this->makeAgent([$this->filterOk([], 'not_found')]);

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Someone Else', 'https://other.example/x'),
        ]));

        $this->assertSame(ResearchOutcome::NotFound, $result->outcome);
        $this->assertStringContainsString('Example Corp', $result->summary);
        $this->assertSame([], $result->sources);
        $this->assertCount(1, $this->aiCalls);
    }

    public function test_not_found_with_name_match_is_rescued_to_uncertain_partial(): void
    {
        $url = 'https://shop.example.com';
        $agent = $this->makeAgent(
            [
                $this->filterOk([], 'not_found'),
                $this->summaryOk('Example Corp makes widgets', [$url]),
            ],
            [$url => $this->page('Example Corp — Shop', $url, 'Example Corp sells widgets.')],
        );

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Example Corp — Shop', $url),
        ]));

        $this->assertSame(ResearchOutcome::Partial, $result->outcome);
        $this->assertSame('Example Corp makes widgets', $result->summary);
        $this->assertSame([['title' => 'Example Corp — Shop', 'url' => $url]], $result->sources);
        $this->assertNotContains(null, [$result->limitations]);
        $this->assertTrue($result->toFindings()['uncertain']);
        $this->assertStringContainsString('Best-effort research', $result->limitations);
        $this->assertSame(ResearchOutcome::Partial->value, $result->toFindings()['outcome']);
        $this->assertTrue($result->audit['rescue_used']);
        $this->assertSame(['obtained' => 1, 'kept' => 1, 'rejected' => 0], $result->audit['counts']);
        $this->assertSame('not_found', $result->audit['filter_outcome']);
        $this->assertSame([['title' => 'Example Corp — Shop', 'url' => $url]], $result->audit['kept']);
    }

    public function test_not_found_without_name_match_stays_not_found(): void
    {
        $agent = $this->makeAgent([$this->filterOk([], 'not_found')]);

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Unrelated News', 'https://news.example'),
        ]));

        $this->assertSame(ResearchOutcome::NotFound, $result->outcome);
        $this->assertFalse($result->toFindings()['uncertain']);
        $this->assertCount(1, $this->aiCalls);
    }

    public function test_ambiguous_with_name_match_is_rescued_to_uncertain_partial(): void
    {
        $a = 'https://a.example';
        $b = 'https://b.example';
        $agent = $this->makeAgent(
            [
                $this->filterOk([], 'ambiguous'),
                $this->summaryOk('Likely about Example Corp.', [$a], 'Could not fully separate the entities.'),
            ],
            [$a => $this->page('Example Corp A', $a), $b => $this->page('Example Corp B', $b)],
        );

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Example Corp A', $a),
            $this->candidate('Example Corp B', $b),
        ]));

        $this->assertSame(ResearchOutcome::Partial, $result->outcome);
        $this->assertTrue($result->toFindings()['uncertain']);
        $this->assertStringContainsString('Best-effort research', $result->limitations);
        $this->assertStringContainsString('Could not fully separate', $result->limitations);
        $this->assertCount(2, $this->aiCalls);
    }

    public function test_ambiguous_without_name_match_stays_ambiguous(): void
    {
        $agent = $this->makeAgent([$this->filterOk([], 'ambiguous')]);

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Alpha Co', 'https://alpha.example'),
            $this->candidate('Beta Co', 'https://beta.example'),
        ]));

        $this->assertSame(ResearchOutcome::Ambiguous, $result->outcome);
        $this->assertFalse($result->toFindings()['uncertain']);
        $this->assertCount(1, $this->aiCalls);
    }

    public function test_rescue_degrades_to_indeterminate_when_matched_pages_cannot_be_fetched(): void
    {
        $agent = $this->makeAgent([$this->filterOk([], 'not_found')], []);

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Example Corp — Shop', 'https://dead.example'),
        ]));

        $this->assertSame(ResearchOutcome::Indeterminate, $result->outcome);
    }

    public function test_rescue_is_disabled_by_rescue_on_name_match_config(): void
    {
        config()->set('web_research.rescue_on_name_match', false);

        $agent = $this->makeAgent([$this->filterOk([], 'not_found')]);

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Example Corp — Shop', 'https://shop.example.com'),
        ]));

        $this->assertSame(ResearchOutcome::NotFound, $result->outcome);
    }

    public function test_malformed_filter_output_is_retried_then_succeeds(): void
    {
        $url = 'https://example.com';
        $agent = $this->makeAgent(
            [null, $this->filterOk([1]), $this->summaryOk('Summary.')],
            [$url => $this->page('About', $url)],
        );

        $result = $agent->research($this->criteria(), $this->payload([$this->candidate('Example Corp', $url)]));

        $this->assertSame(ResearchOutcome::Completed, $result->outcome);
        $this->assertCount(3, $this->aiCalls);
    }

    public function test_malformed_filter_output_every_attempt_fails_indeterminate(): void
    {
        $agent = $this->makeAgent([null, null]);

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Example Corp', 'https://example.com'),
        ]));

        $this->assertSame(ResearchOutcome::Indeterminate, $result->outcome);
        $this->assertCount(2, $this->aiCalls);
    }

    public function test_no_candidates_is_not_found_without_any_ai_call(): void
    {
        $agent = $this->makeAgent([]);

        $result = $agent->research($this->criteria(), $this->payload());

        $this->assertSame(ResearchOutcome::NotFound, $result->outcome);
        $this->assertSame([], $this->aiCalls);
    }

    public function test_indeterminate_when_filter_call_fails(): void
    {
        $agent = $this->makeAgent([null]);

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Example Corp', 'https://example.com'),
        ]));

        $this->assertSame(ResearchOutcome::Indeterminate, $result->outcome);
    }

    public function test_indeterminate_when_summarize_call_fails(): void
    {
        $url = 'https://example.com';
        $agent = $this->makeAgent(
            [$this->filterOk([1]), null],
            [$url => $this->page('Example', $url)],
        );

        $result = $agent->research($this->criteria(), $this->payload([$this->candidate('Example Corp', $url)]));

        $this->assertSame(ResearchOutcome::Indeterminate, $result->outcome);
    }

    public function test_indeterminate_when_no_kept_source_can_be_fetched(): void
    {
        $agent = $this->makeAgent([$this->filterOk([1])], []);

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Example Corp', 'https://dead.example'),
        ]));

        $this->assertSame(ResearchOutcome::Indeterminate, $result->outcome);
    }

    public function test_partial_when_some_kept_sources_fail_to_fetch(): void
    {
        $good = 'https://good.example';
        $bad = 'https://bad.example';
        $agent = $this->makeAgent(
            [$this->filterOk([1, 2]), $this->summaryOk('Partial summary.')],
            [$good => $this->page('Good', $good)],
        );

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Good', $good),
            $this->candidate('Bad', $bad),
        ]));

        $this->assertSame(ResearchOutcome::Partial, $result->outcome);
        $this->assertSame([['title' => 'Good', 'url' => $good]], $result->sources);
    }

    public function test_sources_are_remapped_to_fetched_urls_only(): void
    {
        $url = 'https://example.com';
        $agent = $this->makeAgent(
            [
                $this->filterOk([1]),
                $this->summaryOk('Summary.', [
                    ['title' => 'Real', 'url' => $url],
                    ['title' => 'Hallucinated', 'url' => 'https://not-fetched.example'],
                ]),
            ],
            [$url => $this->page('Real', $url)],
        );

        $result = $agent->research($this->criteria(), $this->payload([$this->candidate('Example', $url)]));

        $this->assertSame([['title' => 'Real', 'url' => $url]], $result->sources);
    }

    public function test_kept_sources_are_capped_at_max_sources(): void
    {
        config()->set('web_research.max_sources', 1);

        $a = 'https://a.example';
        $b = 'https://b.example';
        $agent = $this->makeAgent([$this->filterOk([1, 2]), $this->summaryOk('One.')], [
            $a => $this->page('A', $a),
            $b => $this->page('B', $b),
        ]);

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('A', $a),
            $this->candidate('B', $b),
        ]));

        $this->assertSame([['title' => 'A', 'url' => $a]], $result->sources);
    }

    public function test_candidates_are_capped_at_max_candidates_in_the_filter_prompt(): void
    {
        config()->set('web_research.max_candidates', 1);

        $agent = $this->makeAgent([$this->filterOk([])]);

        $agent->research($this->criteria(), $this->payload([
            $this->candidate('One', 'https://one.example'),
            $this->candidate('Two', 'https://two.example'),
        ]));

        $this->assertStringContainsString('one.example', $this->aiCalls[0]['user']);
        $this->assertStringNotContainsString('two.example', $this->aiCalls[0]['user']);
    }

    public function test_budget_exhausted_degrades_to_indeterminate(): void
    {
        config()->set('web_research.step_timeout', 0);

        $agent = $this->makeAgent([]);

        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Example Corp', 'https://example.com'),
        ]));

        $this->assertSame(ResearchOutcome::Indeterminate, $result->outcome);
        $this->assertSame([], $this->aiCalls);
    }

    public function test_missing_person_still_completes_and_summary_names_it(): void
    {
        $url = 'https://example.com';
        $agent = $this->makeAgent(
            [
                $this->filterOk([1]),
                $this->summaryOk('Example Corp is a UK fintech. The named person could not be established.'),
            ],
            [$url => $this->page('Example Corp', $url)],
        );

        $result = $agent->research(
            $this->criteria(['person' => ['first_name' => 'Jane', 'last_name' => 'Doe']]),
            $this->payload([$this->candidate('Example Corp', $url)]),
        );

        $this->assertSame(ResearchOutcome::Completed, $result->outcome);
        $this->assertStringContainsString('could not be established', $result->summary);
    }
}
