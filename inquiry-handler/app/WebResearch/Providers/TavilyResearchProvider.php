<?php

namespace App\WebResearch\Providers;

use App\WebResearch\WebResearchProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Real web-research provider backed by the Tavily search API (replaces the
 * no-op skeleton). One lookup per section, best-effort:
 *
 *  - company lookup skipped when $criteria['company'] is null (no name given);
 *  - a failed lookup degrades that section to an empty array so a working
 *    company search still yields person results (and vice versa);
 *  - a missing TAVILY_API_KEY throws, which WebResearchService turns into an
 *    indeterminate/fail-open verdict.
 *
 * Only the criteria fields WebResearchService builds are ever used — company
 * name + country/region and the person's name with company context. Email and
 * phone are never part of any query.
 */
class TavilyResearchProvider implements WebResearchProvider
{
    public function research(array $criteria): array
    {
        $key = (string) config('services.tavily.key');

        if ($key === '') {
            throw new \RuntimeException('Missing TAVILY_API_KEY configuration.');
        }

        return [
            'company' => $this->search($key, $this->companyQuery($criteria)),
            'person' => $this->search($key, $this->personQuery($criteria)),
        ];
    }

    /**
     * @param  array{company: array{name: string, country_region: string|null}|null, person: array{first_name: string, last_name: string, country_region?: string, company_context?: string}}  $criteria
     */
    private function companyQuery(array $criteria): ?string
    {
        $company = $criteria['company'] ?? null;

        if (! is_array($company) || ! is_string($company['name'] ?? null) || $company['name'] === '') {
            return null;
        }

        $query = trim($company['name']);

        if (is_string($company['country_region'] ?? null) && $company['country_region'] !== '') {
            $query .= ' '.$company['country_region'];
        }

        return trim($query).' company';
    }

    /**
     * @param  array{company: array{name: string, country_region: string|null}|null, person: array{first_name: string, last_name: string, country_region?: string, company_context?: string}}  $criteria
     */
    private function personQuery(array $criteria): string
    {
        $person = $criteria['person'];
        $query = trim($person['first_name'].' '.$person['last_name']);

        if (is_string($person['company_context'] ?? null) && $person['company_context'] !== '') {
            $query .= ' '.$person['company_context'];
        }

        if (is_string($person['country_region'] ?? null) && $person['country_region'] !== '') {
            $query .= ' '.$person['country_region'];
        }

        return trim($query);
    }

    /**
     * @return array{query: string|null, summary: string|null, found: bool, results: array<int, array{title: string, url: string, snippet: string, score: float, published_date: string|null}>}
     */
    private function search(string $key, ?string $query): array
    {
        if ($query === null || $query === '') {
            return ['query' => null, 'summary' => null, 'found' => false, 'results' => []];
        }

        try {
            $response = $this->call($key, $query);
        } catch (ConnectionException | RequestException) {
            return ['query' => $query, 'summary' => null, 'found' => false, 'results' => []];
        }

        $results = $this->results($response->json('results'));
        $summary = is_string($response->json('answer')) ? $response->json('answer') : null;

        return [
            'query' => $query,
            'summary' => $summary,
            'found' => $results !== [],
            'results' => $results,
        ];
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    private function call(string $key, string $query): Response
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(15)
            ->post((string) config('services.tavily.url', 'https://api.tavily.com/search'), [
                'api_key' => $key,
                'query' => $query,
                'search_depth' => (string) config('services.tavily.search_depth', 'basic'),
                'max_results' => max(1, (int) config('services.tavily.max_results', 5)),
                'include_answer' => true,
            ])
            ->throw();
    }

    /**
     * @param  mixed  $items
     * @return array<int, array{title: string, url: string, snippet: string, score: float, published_date: string|null}>
     */
    private function results(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = is_string($item['title'] ?? null) ? $item['title'] : '';
            $url = is_string($item['url'] ?? null) ? $item['url'] : '';

            if ($title === '' && $url === '') {
                continue;
            }

            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => mb_strimwidth(
                    is_string($item['content'] ?? null) ? $item['content'] : '',
                    0,
                    1000,
                    '…',
                ),
                'score' => is_numeric($item['score'] ?? null) ? (float) $item['score'] : 0.0,
                'published_date' => is_string($item['published_date'] ?? null) ? $item['published_date'] : null,
            ];
        }

        return $results;
    }
}