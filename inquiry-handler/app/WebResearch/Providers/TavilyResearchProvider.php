<?php

namespace App\WebResearch\Providers;

use App\WebResearch\WebResearchProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Real web-research provider backed by the Tavily Search API.
 *
 * Each research topic gets ONE short query (mostly just the entity name plus a
 * single topic word) so the results stay about the company/person rather than
 * about the keywords. Each topic also asks for only as many results as it
 * realistically needs.
 *
 * COMPANY topics (query → max results)
 *   overview   "{company} {country}"            3
 *   leadership "{company} founder CEO"          2
 *   offerings  "{company} products and services" 2
 *   clients    "{company} clients and projects" 2
 *   news       "{company} news"                 3
 *   tech       "{company} GitHub"               1
 *   hiring     "{company} careers"              2
 *
 * PERSON topic
 *   person     "{name} {company context}"       3
 *
 * Every result carries a `topic` tag and URLs are de-duplicated across topics.
 *
 * The public response shape remains:
 *
 * [
 *     'company' => [...],
 *     'person' => [...],
 * ]
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
            'company' => $this->searchMultiple($key, $this->companyQueries($criteria)),
            'person' => $this->searchMultiple($key, $this->personQueries($criteria)),
        ];
    }

    /**
     * @return array<int, array{topic: string, query: string, max_results: int}>
     */
    private function companyQueries(array $criteria): array
    {
        $company = $criteria['company'] ?? null;

        if (
            ! is_array($company) ||
            ! is_string($company['name'] ?? null) ||
            trim($company['name']) === ''
        ) {
            return [];
        }

        $name = trim($company['name']);

        // The country is the main disambiguator, so only the overview query
        // carries it; the other topics stay as clean as possible.
        $country = is_string($company['country_region'] ?? null)
            ? trim($company['country_region'])
            : '';

        return [
            $this->question('overview', trim("{$name} {$country}"), 3),
            $this->question('leadership', "{$name} founder CEO", 2),
            $this->question('offerings', "{$name} products and services", 2),
            $this->question('clients', "{$name} clients and projects", 2),
            $this->question('news', "{$name} news", 3),
            $this->question('tech', "{$name} GitHub", 1),
            $this->question('hiring', "{$name} careers", 2),
        ];
    }

    /**
     * @return array<int, array{topic: string, query: string, max_results: int}>
     */
    private function personQueries(array $criteria): array
    {
        $person = $criteria['person'] ?? null;

        if (! is_array($person)) {
            return [];
        }

        $firstName = trim((string) ($person['first_name'] ?? ''));
        $lastName = trim((string) ($person['last_name'] ?? ''));

        if ($firstName === '' && $lastName === '') {
            return [];
        }

        $context = is_string($person['company_context'] ?? null)
            ? trim($person['company_context'])
            : '';

        return [
            $this->question('person', trim("{$firstName} {$lastName} {$context}"), 3),
        ];
    }

    /**
     * @return array{topic: string, query: string, max_results: int}
     */
    private function question(string $topic, string $query, int $maxResults): array
    {
        return [
            'topic' => $topic,
            'query' => $query,
            'max_results' => $maxResults,
        ];
    }

    /**
     * Run every question and merge the results, dropping URLs already seen.
     *
     * @param array<int, array{topic: string, query: string, max_results: int}> $questions
     *
     * @return array{
     *     query: array<int, string>,
     *     summary: string|null,
     *     found: bool,
     *     results: array<int, array{
     *         topic: string,
     *         title: string,
     *         url: string,
     *         snippet: string,
     *         score: float,
     *         published_date: string|null
     *     }>
     * }
     */
    private function searchMultiple(string $key, array $questions): array
    {
        if ($questions === []) {
            return [
                'query' => [],
                'summary' => null,
                'found' => false,
                'results' => [],
            ];
        }

        $allResults = [];
        $seen = [];

        foreach ($questions as $question) {
            try {
                $response = $this->call($key, $question['query'], $question['max_results']);
            } catch (ConnectionException | RequestException) {
                continue;
            }

            $results = $this->results($response->json('results'), $question['topic']);

            foreach ($results as $result) {
                $id = strtolower(rtrim($result['url'], '/'));

                if ($id !== '') {
                    if (isset($seen[$id])) {
                        continue;
                    }

                    $seen[$id] = true;
                }

                $allResults[] = $result;
            }
        }

        return [
            'query' => array_column($questions, 'query'),
            // Tavily's own answer is no longer requested: the agent never used it.
            'summary' => null,
            'found' => $allResults !== [],
            'results' => $allResults,
        ];
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    private function call(string $key, string $query, int $maxResults): Response
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(
                (string) config(
                    'services.tavily.url',
                    'https://api.tavily.com/search'
                ),
                [
                    'api_key' => $key,
                    'query' => $query,
                    'search_depth' => (string) config(
                        'services.tavily.search_depth',
                        'basic'
                    ),
                    'max_results' => max(1, min(10, $maxResults)),
                    'include_answer' => false,
                ]
            )
            ->throw();
    }

    /**
     * @param mixed $items
     *
     * @return array<int, array{
     *     topic: string,
     *     title: string,
     *     url: string,
     *     snippet: string,
     *     score: float,
     *     published_date: string|null
     * }>
     */
    private function results(mixed $items, string $topic): array
    {
        if (! is_array($items)) {
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = is_string($item['title'] ?? null)
                ? $item['title']
                : '';

            $url = is_string($item['url'] ?? null)
                ? $item['url']
                : '';

            if ($title === '' && $url === '') {
                continue;
            }

            $results[] = [
                'topic' => $topic,
                'title' => $title,
                'url' => $url,
                'snippet' => mb_strimwidth(
                    is_string($item['content'] ?? null)
                        ? $item['content']
                        : '',
                    0,
                    1000,
                    '…',
                ),
                'score' => is_numeric($item['score'] ?? null)
                    ? (float) $item['score']
                    : 0.0,
                'published_date' => is_string(
                    $item['published_date'] ?? null
                )
                    ? $item['published_date']
                    : null,
            ];
        }

        return $results;
    }
}