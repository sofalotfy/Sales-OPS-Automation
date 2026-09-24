<?php

namespace Tests\Unit;

use App\Services\AiCallingService;
use App\WebResearch\PageFetcher;
use App\WebResearch\ResearchAgent;
use App\WebResearch\ResearchOutcome;
use App\WebResearch\ResearchResult;
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

            public function fetchMany(array $urls, ?float $deadline = null): array
            {
                $documents = [];

                foreach ($urls as $url) {
                    $documents[$url] = $this->pages[$url] ?? null;
                }

                return $documents;
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
        // The candidate filter uses the cheap/fast model; the final summary the
        // strong research model.
        $filterModel = (string) config('services.ai.filter_model', 'openai/gpt-oss-20b');
        $researchModel = (string) config('services.ai.research_model', 'openai/gpt-oss-120b');
        $this->assertSame($filterModel, $this->aiCalls[0]['model']);
        $this->assertSame($researchModel, $this->aiCalls[1]['model']);

        $this->assertSame(['obtained' => 2, 'kept' => 1, 'rejected' => 1], $result->audit['counts']);
        $this->assertSame([['title' => 'Example Corp — About', 'url' => $url]], $result->audit['kept']);
        $this->assertSame([['title' => '10 Best Companies', 'url' => 'https://aggregator.example/list']], $result->audit['rejected']);
        $this->assertSame('ok', $result->audit['filter_outcome']);
        $this->assertSame([1], $result->audit['filter_keep']);
        $this->assertFalse($result->audit['rescue_used']);
    }

    public function test_research_uses_the_configured_filter_and_research_models(): void
    {
        config(['services.ai.filter_model' => 'filter-test-model']);
        config(['services.ai.research_model' => 'research-test-model']);

        $url = 'https://example.com/about';
        $agent = $this->makeAgent(
            [$this->filterOk([1]), $this->summaryOk('Summary.')],
            [$url => $this->page('About', $url)],
        );

        $agent->research($this->criteria(), $this->payload([$this->candidate('Example Corp', $url)]));

        $this->assertCount(2, $this->aiCalls);
        $this->assertSame('filter-test-model', $this->aiCalls[0]['model']);
        $this->assertSame('research-test-model', $this->aiCalls[1]['model']);
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

    public function test_layer1_batches_run_concurrently_with_the_research_model(): void
    {
        // Small per-call budget with long pages forces layer 1: each page is its
        // own batch, and all batches are analysed in ONE concurrent round.
        config()->set('web_research.summary_max_input_chars', 1000);
        config()->set('web_research.note_attempts', 1);

        $urls = ['https://a.example', 'https://b.example', 'https://c.example'];
        $pages = [];
        $candidates = [];

        foreach ($urls as $i => $url) {
            $pages[$url] = $this->page('Page '.$i, $url, str_repeat('x', 500).' body');
            $candidates[] = $this->candidate('Example Corp '.$i, $url);
        }

        $concurrentRounds = 0;
        $ai = Mockery::mock(AiCallingService::class);
        $ai->shouldReceive('complete')->andReturnUsing(
            function (string $system, string $user, ?string $model = null) {
                $this->aiCalls[] = ['system' => $system, 'user' => $user, 'model' => $model ?? ''];

                // First completion is the candidate filter; the last the final summary.
                return count($this->aiCalls) === 1
                    ? $this->filterOk([1, 2, 3])
                    : $this->summaryOk('Final profile from concurrent notes.');
            },
        );
        $ai->shouldReceive('completeMany')->andReturnUsing(
            function (array $jobs) use (&$concurrentRounds) {
                $concurrentRounds++;
                $results = [];

                foreach ($jobs as $job) {
                    $this->aiCalls[] = ['system' => $job['system'], 'user' => $job['user'], 'model' => $job['model'] ?? ''];
                    $results[$job['key']] = ['notes' => [['id' => 1, 'relevant' => true, 'facts' => 'Facts about the page.']]];
                }

                return $results;
            },
        );

        $agent = new ResearchAgent($ai, $this->fakeFetcher($pages));
        $result = $agent->research($this->criteria(), $this->payload($candidates));

        $this->assertSame(ResearchOutcome::Completed, $result->outcome);
        $this->assertSame('Final profile from concurrent notes.', $result->summary);
        $this->assertCount(3, $result->sources);
        $this->assertSame(1, $concurrentRounds);

        // filter + 3 concurrent notes + 1 final summary = 5 AI turns, all notes
        // on the strong research model and the filter on the cheap one.
        $this->assertCount(5, $this->aiCalls);
        $this->assertSame((string) config('services.ai.filter_model', 'openai/gpt-oss-20b'), $this->aiCalls[0]['model']);

        $researchModel = (string) config('services.ai.research_model', 'openai/gpt-oss-120b');
        $this->assertSame($researchModel, $this->aiCalls[1]['model']);
        $this->assertSame($researchModel, $this->aiCalls[2]['model']);
        $this->assertSame($researchModel, $this->aiCalls[3]['model']);
        $this->assertSame($researchModel, $this->aiCalls[4]['model']);
    }

    public function test_layer1_unparseable_batch_is_retried_then_recorded_as_failed(): void
    {
        config()->set('web_research.summary_max_input_chars', 1000);
        config()->set('web_research.note_attempts', 2);

        $url = 'https://a.example';
        $pages = [$url => $this->page('Page A', $url, str_repeat('x', 1200).' body')];

        $ai = Mockery::mock(AiCallingService::class);
        $ai->shouldReceive('complete')->andReturnUsing(
            function (string $system, string $user, ?string $model = null) {
                $this->aiCalls[] = ['system' => $system, 'user' => $user, 'model' => $model ?? ''];

                return count($this->aiCalls) === 1
                    ? $this->filterOk([1])
                    : null; // final summary never runs: every notes call failed
            },
        );
        $ai->shouldReceive('completeMany')->andReturnUsing(function (array $jobs) {
            $results = [];

            foreach ($jobs as $job) {
                $this->aiCalls[] = ['system' => $job['system'], 'user' => $job['user'], 'model' => $job['model'] ?? ''];
                $results[$job['key']] = null; // unparseable output every attempt
            }

            return $results;
        });

        $agent = new ResearchAgent($ai, $this->fakeFetcher($pages));
        $result = $agent->research($this->criteria(), $this->payload([$this->candidate('Example Corp', $url)]));

        $this->assertSame(ResearchOutcome::Indeterminate, $result->outcome);
        // filter + 2 note_attempts rounds of the same batch
        $this->assertCount(3, $this->aiCalls);
    }

    // --------------------------------------------- feature 011: extraction

    /**
     * Run one forced layer-1 pipeline (a page too big for a single call) with
     * the notes call returning `$facts` verbatim, and return the research result.
     */
    private function runNotesExtraction(string $facts): ResearchResult
    {
        config()->set('web_research.summary_max_input_chars', 1000);
        config()->set('web_research.note_attempts', 1);

        $url = 'https://example.com';
        $ai = Mockery::mock(AiCallingService::class);
        $ai->shouldReceive('complete')->andReturnUsing(
            function (string $system, string $user, ?string $model = null) {
                $this->aiCalls[] = ['system' => $system, 'user' => $user, 'model' => $model ?? ''];

                return count($this->aiCalls) === 1
                    ? $this->filterOk([1])
                    : $this->summaryOk('Extracted profile: revenue $48.2M; about 1,200 employees; founded 2013; office in London, UK.');
            },
        );
        $ai->shouldReceive('completeMany')->andReturnUsing(function (array $jobs) use ($facts) {
            $results = [];

            foreach ($jobs as $job) {
                $this->aiCalls[] = ['system' => $job['system'], 'user' => $job['user'], 'model' => $job['model'] ?? ''];
                $results[$job['key']] = ['notes' => [['id' => 1, 'relevant' => true, 'facts' => $facts]]];
            }

            return $results;
        });

        $agent = new ResearchAgent($ai, $this->fakeFetcher([
            $url => $this->page('About', $url, str_repeat('x', 1200).' body'),
        ]));

        return $agent->research($this->criteria(), $this->payload([$this->candidate('Example Corp', $url)]));
    }

    public function test_layer1_notes_keep_verbatim_figures_beyond_the_old_800_char_cap(): void
    {
        // The figures sit past character 800 of the note (feature 011).
        $longFacts = str_repeat('detail ', 120).'revenue $48.2M; about 1,200 employees; founded 2013; office in London, UK.';

        $result = $this->runNotesExtraction($longFacts);

        $this->assertSame(ResearchOutcome::Completed, $result->outcome);
        $this->assertStringContainsString('$48.2M', $result->summary);
        $this->assertStringContainsString('1,200 employees', $result->summary);

        // The final-call input must carry the full extraction, figures included.
        $final = end($this->aiCalls);
        $this->assertStringContainsString('$48.2M', (string) $final['user']);
        $this->assertStringContainsString('1,200 employees', (string) $final['user']);
        $this->assertStringContainsString('founded 2013', (string) $final['user']);
    }

    public function test_parse_notes_retains_extractions_longer_than_800_chars(): void
    {
        // 'information ' x 70 = 840 chars, so 'founded 2013' sits past 800.
        $longFacts = str_repeat('information ', 70).'revenue $48.2M; about 1,200 employees; founded 2013.';

        $this->runNotesExtraction($longFacts);

        $final = end($this->aiCalls);
        $this->assertStringContainsString('founded 2013', (string) $final['user']);
        $this->assertStringContainsString('$48.2M', (string) $final['user']);
    }

    public function test_notes_system_prompt_extracts_without_a_word_cap(): void
    {
        $this->runNotesExtraction('Extracted facts about Example Corp.');

        $system = mb_strtolower((string) $this->aiCalls[1]['system']);

        $this->assertStringNotContainsString('120 words', $system);
        $this->assertStringContainsString('extract', $system);
        $this->assertStringContainsString('verbatim', $system);
    }

    public function test_summary_system_prompt_extracts_instead_of_condensing(): void
    {
        $url = 'https://example.com';
        $agent = $this->makeAgent(
            [$this->filterOk([1]), $this->summaryOk('Extracted profile.')],
            [$url => $this->page('About', $url)],
        );

        $agent->research($this->criteria(), $this->payload([$this->candidate('Example Corp', $url)]));

        $system = mb_strtolower((string) $this->aiCalls[1]['system']);

        $this->assertStringNotContainsString('concise', $system);
        $this->assertStringContainsString('extract', $system);
        $this->assertStringContainsString('verbatim', $system);
    }

    public function test_layer2_extraction_passes_cover_every_note(): void
    {
        // The notes exceed the per-call cap, forcing layer 2 to run in passes.
        config()->set('web_research.summary_max_input_chars', 2000);
        config()->set('web_research.note_attempts', 1);

        $urls = ['https://a.example', 'https://b.example', 'https://c.example'];
        $markers = ['markerAlpha', 'markerBeta', 'markerGamma'];
        $pages = [];
        $candidates = [];

        foreach ($urls as $i => $url) {
            $pages[$url] = $this->page('Page '.$i, $url, str_repeat('x', 1200).' body');
            $candidates[] = $this->candidate('Example Corp '.$i, $url);
        }

        $ai = Mockery::mock(AiCallingService::class);
        $ai->shouldReceive('complete')->andReturnUsing(
            function (string $system, string $user, ?string $model = null) {
                $this->aiCalls[] = ['system' => $system, 'user' => $user, 'model' => $model ?? ''];

                if (count($this->aiCalls) === 1) {
                    return $this->filterOk([1, 2, 3]);
                }

                // Echo what this pass received, so the merged result proves
                // every note's content reached at least one layer-2 pass.
                return ['summary' => (string) $user, 'sources' => [], 'limitations' => null];
            },
        );
        $ai->shouldReceive('completeMany')->andReturnUsing(function (array $jobs) use ($markers) {
            $results = [];

            foreach ($jobs as $job) {
                $this->aiCalls[] = ['system' => $job['system'], 'user' => $job['user'], 'model' => $job['model'] ?? ''];
                $index = (int) $job['key'];
                $results[$job['key']] = [
                    'notes' => [['id' => 1, 'relevant' => true, 'facts' => $markers[$index].' '.str_repeat('y', 3000)]],
                ];
            }

            return $results;
        });

        $agent = new ResearchAgent($ai, $this->fakeFetcher($pages));
        $result = $agent->research($this->criteria(), $this->payload($candidates));

        $this->assertSame(ResearchOutcome::Completed, $result->outcome);

        foreach ($markers as $marker) {
            $this->assertStringContainsString($marker, $result->summary, "Layer-2 dropped {$marker}.");
        }

        // filter + 3 notes + 3 extraction passes
        $this->assertCount(7, $this->aiCalls);
    }

    public function test_extraction_prompt_retains_conflicting_statements(): void
    {
        $url = 'https://example.com';
        $agent = $this->makeAgent(
            [
                $this->filterOk([1]),
                $this->summaryOk('One document says founded 2013; another says founded 2011.'),
            ],
            [$url => $this->page('About', $url)],
        );

        $result = $agent->research($this->criteria(), $this->payload([$this->candidate('Example Corp', $url)]));

        $this->assertSame(ResearchOutcome::Completed, $result->outcome);
        $this->assertStringContainsString('2013', $result->summary);
        $this->assertStringContainsString('2011', $result->summary);

        $system = mb_strtolower((string) $this->aiCalls[1]['system']);
        $this->assertStringNotContainsString('concise', $system);
        $this->assertStringContainsString('conflict', $system);
    }

    public function test_layer2_partial_run_names_the_failed_batch_gap(): void
    {
        config()->set('web_research.summary_max_input_chars', 1000);
        config()->set('web_research.note_attempts', 1);

        $good = 'https://good.example';
        $bad = 'https://bad.example';

        $ai = Mockery::mock(AiCallingService::class);
        $ai->shouldReceive('complete')->andReturnUsing(
            function (string $system, string $user, ?string $model = null) {
                $this->aiCalls[] = ['system' => $system, 'user' => $user, 'model' => $model ?? ''];

                return count($this->aiCalls) === 1
                    ? $this->filterOk([1, 2])
                    : $this->summaryOk('Extraction from the good batch.');
            },
        );
        $ai->shouldReceive('completeMany')->andReturnUsing(function (array $jobs) {
            $results = [];

            foreach ($jobs as $job) {
                $this->aiCalls[] = ['system' => $job['system'], 'user' => $job['user'], 'model' => $job['model'] ?? ''];

                $results[$job['key']] = $job['key'] === '0'
                    ? ['notes' => [['id' => 1, 'relevant' => true, 'facts' => 'Good page facts.']]]
                    : null; // second batch fails on its only attempt
            }

            return $results;
        });

        $agent = new ResearchAgent($ai, $this->fakeFetcher([
            $good => $this->page('Good', $good, str_repeat('x', 1200).' body'),
            $bad => $this->page('Bad', $bad, str_repeat('x', 1200).' body'),
        ]));
        $result = $agent->research($this->criteria(), $this->payload([
            $this->candidate('Good', $good),
            $this->candidate('Bad', $bad),
        ]));

        $this->assertSame(ResearchOutcome::Partial, $result->outcome);
        $this->assertStringContainsString('1 batch(es) failed', (string) $result->limitations);
        $this->assertSame([['title' => 'Good', 'url' => $good]], $result->sources);
    }
}
