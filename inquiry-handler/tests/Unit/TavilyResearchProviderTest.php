<?php

namespace Tests\Unit;

use App\WebResearch\Providers\TavilyResearchProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TavilyResearchProvider unit tests.
 *
 * Fakes the Tavily endpoint and asserts the queries built from the criteria:
 * company name + country/region and the person's name + company context — and,
 * crucially, that email/phone can never appear in any outbound query.
 */
class TavilyResearchProviderTest extends TestCase
{
    private function url(): string
    {
        return (string) config('services.tavily.url');
    }

    /** @return array{company: array{name: string, country_region: string|null}|null, person: array{first_name: string, last_name: string, country_region: string, company_context: string}} */
    private function criteria(array $overrides = []): array
    {
        return array_merge([
            'company' => ['name' => 'Example Corp', 'country_region' => 'United Kingdom'],
            'person' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'country_region' => 'United Kingdom',
                'company_context' => 'Example Corp',
            ],
        ], $overrides);
    }

    public function test_missing_api_key_throws(): void
    {
        config(['services.tavily.key' => '']);

        $this->expectException(\RuntimeException::class);

        (new TavilyResearchProvider())->research($this->criteria());
    }

    public function test_company_and_person_queries_are_contact_safe(): void
    {
        config(['services.tavily.key' => 'test-key']);

        $queries = [];
        Http::fake(function ($request) use (&$queries) {
            $queries[] = $request->data()['query'] ?? '';

            return Http::response(['query' => '', 'results' => []], 200);
        });

        $research = (new TavilyResearchProvider())->research($this->criteria());

        // 7 company research questions + 1 key-person question. The country is
        // only used as the overview disambiguator; the other topics stay clean.
        $this->assertSame([
            'Example Corp United Kingdom',
            'Example Corp founder CEO',
            'Example Corp products and services',
            'Example Corp clients and projects',
            'Example Corp news',
            'Example Corp GitHub',
            'Example Corp careers',
        ], $research['company']['query']);
        $this->assertSame(['Jane Doe Example Corp'], $research['person']['query']);

        $this->assertCount(8, $queries);

        foreach ($queries as $query) {
            $this->assertStringNotContainsString('@', $query);
            $this->assertStringNotContainsString('555', $query);
            $this->assertStringNotContainsString('jane', $query);
        }

        Http::assertSent(fn ($req) => $req->url() === $this->url());
    }

    public function test_results_are_mapped_into_sections_with_topic_tags_and_dedup(): void
    {
        config(['services.tavily.key' => 'test-key']);

        // Every company question resolves to the same "about" URL (the provider
        // de-duplicates across topics); leadership re-serves it plus a fresh URL.
        // The single person question resolves to its own payload.
        Http::fake(function ($request) {
            $query = $request->data()['query'] ?? '';

            if (str_starts_with($query, 'Jane Doe')) {
                return Http::response([
                    'answer' => '',
                    'results' => [
                        ['title' => 'Jane Doe — Profile', 'url' => 'https://example.com/jane', 'content' => 'person page', 'score' => 0.8, 'published_date' => '2024-02-01'],
                    ],
                ], 200);
            }

            if (str_contains($query, 'founder CEO')) {
                return Http::response([
                    'answer' => '',
                    'results' => [
                        ['title' => 'Example Corp — About', 'url' => 'https://example.com/about', 'content' => 'dup, skipped', 'score' => 0.5],
                        ['title' => 'Example Corp — Leadership', 'url' => 'https://example.com/leadership', 'content' => 'leadership', 'score' => 0.7],
                    ],
                ], 200);
            }

            return Http::response([
                'answer' => '',
                'results' => [
                    ['title' => 'Example Corp — About', 'url' => 'https://example.com/about', 'content' => str_repeat('a', 1500), 'score' => 0.9, 'published_date' => '2024-01-15'],
                    ['title' => '', 'url' => '', 'content' => 'no identifiers, skipped'],
                ],
            ], 200);
        });

        $research = (new TavilyResearchProvider())->research($this->criteria());

        // 7 company questions → merged, de-duplicated results (the 'about' URL
        // appears once even though 7 questions returned it). No Tavily answer
        // is requested anymore, so the summary is always null.
        $this->assertTrue($research['company']['found']);
        $this->assertCount(7, $research['company']['query']);
        $this->assertNull($research['company']['summary']);
        $this->assertCount(2, $research['company']['results']);

        $this->assertSame('overview', $research['company']['results'][0]['topic']);
        $this->assertSame('Example Corp — About', $research['company']['results'][0]['title']);
        $this->assertSame('https://example.com/about', $research['company']['results'][0]['url']);
        $this->assertSame(0.9, $research['company']['results'][0]['score']);
        $this->assertSame('2024-01-15', $research['company']['results'][0]['published_date']);
        $this->assertLessThan(1500, strlen($research['company']['results'][0]['snippet']));

        $this->assertSame('leadership', $research['company']['results'][1]['topic']);
        $this->assertSame('Example Corp — Leadership', $research['company']['results'][1]['title']);

        // 1 person question → topic-tagged single result, no summary.
        $this->assertTrue($research['person']['found']);
        $this->assertSame(['Jane Doe Example Corp'], $research['person']['query']);
        $this->assertNull($research['person']['summary']);
        $this->assertCount(1, $research['person']['results']);
        $this->assertSame('person', $research['person']['results'][0]['topic']);
        $this->assertSame('Jane Doe — Profile', $research['person']['results'][0]['title']);
    }

    public function test_company_lookup_is_skipped_when_no_company_name(): void
    {
        config(['services.tavily.key' => 'test-key']);

        $queries = [];
        Http::fake(function ($request) use (&$queries) {
            $queries[] = $request->data()['query'] ?? '';

            return Http::response(['query' => '', 'results' => []], 200);
        });

        $research = (new TavilyResearchProvider())->research($this->criteria([
            'company' => null,
            'person' => ['first_name' => 'Jane', 'last_name' => 'Doe'],
        ]));

        $this->assertCount(1, $queries);
        $this->assertSame('Jane Doe', $queries[0]);
        $this->assertSame([], $research['company']['query']);
        $this->assertSame(null, $research['company']['summary']);
        $this->assertFalse($research['company']['found']);
        $this->assertSame([], $research['company']['results']);
    }

    public function test_search_failure_degrades_section_to_empty(): void
    {
        config(['services.tavily.key' => 'test-key']);

        Http::fake([
            $this->url() => Http::response(['error' => 'provider down'], 500),
        ]);

        $research = (new TavilyResearchProvider())->research($this->criteria());

        $this->assertFalse($research['company']['found']);
        $this->assertSame([], $research['company']['results']);
        $this->assertFalse($research['person']['found']);
        $this->assertSame([], $research['person']['results']);
    }
}