<?php

namespace App\WebResearch;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bounded page fetcher for the AI research agent (feature 010, research R3).
 *
 * Retrieves kept source pages and returns their extracted visible text, or
 * `null` on ANY failure (unsupported scheme, connection/timeout, non-2xx,
 * non-HTML content, empty text). The agent skips a `null` source rather than
 * aborting the run (US2 acceptance 2).
 *
 * `fetchMany()` downloads pages concurrently in small batches
 * (`fetch_concurrency`), so 40 pages take a few timeouts instead of 40.
 *
 * Bounds (config/web_research.php):
 *  - `fetch_timeout`     seconds;
 *  - `fetch_concurrency` simultaneous downloads;
 *  - `fetch_max_bytes`   downloaded body cap;
 *  - `source_max_chars`  extracted-text cap.
 *
 * Fetched content is treated strictly as data — it is never executed or
 * interpreted as instructions (FR-009).
 */
class PageFetcher
{
    private readonly UrlGuard $guard;

    public function __construct(?UrlGuard $guard = null)
    {
        $this->guard = $guard ?? new UrlGuard();
    }

    /**
     * Fetch a single page.
     *
     * @return array{title: string, url: string, text: string}|null
     */
    public function fetch(string $url): ?array
    {
        if (! $this->isHttpUrl($url)) {
            return null;
        }

        $target = $this->guard->validate($url);

        if ($target === null) {
            // Fail closed: the guard rejected this target (private IP, docker
            // service name, non-public DNS record, ...). Callers treat a null
            // like any other fetch failure — the agent skips this source and
            // keeps the batch going (US2 acceptance 2).
            return null;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout())
                ->withOptions($this->options())
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if ($response === null) {
            return null;
        }

        return $this->toDocument($url, $response);
    }

    /**
     * Fetch many pages concurrently.
     *
     * Returns a map of url => document (or null when that page failed).
     * Stops starting new batches once `$deadline` (a microtime float) passes.
     *
     * @param  array<int, string>  $urls
     * @return array<string, array{title: string, url: string, text: string}|null>
     */
    public function fetchMany(array $urls, ?float $deadline = null): array
    {
        $urls = array_values(array_unique($urls));
        $documents = [];
        $batchSize = max(1, (int) config('web_research.fetch_concurrency', 10));
        $timeout = $this->timeout();

        foreach ($urls as $url) {
            $documents[$url] = null;
        }

        foreach (array_chunk($urls, $batchSize) as $batch) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            $valid = array_values(array_filter($batch, fn (string $url): bool => $this->isHttpUrl($url)));

            if ($valid === []) {
                continue;
            }

            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (string $url) => $pool->as($url)
                        ->withHeaders($this->headers())
                        ->timeout($timeout)
                        ->withOptions($this->options())
                        ->get($url),
                    $valid,
                ));
            } catch (Throwable) {
                continue;
            }

            foreach ($responses as $url => $response) {
                // A failed connection comes back as an exception object, not a Response.
                if ($response instanceof Response) {
                    $documents[(string) $url] = $this->toDocument((string) $url, $response);
                }
            }
        }

        return $documents;
    }

    /**
     * @return array{title: string, url: string, text: string}|null
     */
    private function toDocument(string $url, Response $response): ?array
    {
        if (! $response->successful()) {
            return null;
        }

        if (! $this->isHtmlContentType($response->header('Content-Type'))) {
            return null;
        }

        $body = $response->body();

        if ($body === '') {
            return null;
        }

        $maxBytes = max(1024, (int) config('web_research.fetch_max_bytes', 200000));
        $maxChars = max(1, (int) config('web_research.source_max_chars', 8000));

        if (strlen($body) > $maxBytes) {
            $body = substr($body, 0, $maxBytes);
        }

        $text = $this->extractText($body, $maxChars);

        if ($text === '') {
            return null;
        }

        $title = $this->extractTitle($body);

        return [
            'title' => $title !== '' ? $title : $url,
            'url' => $url,
            'text' => $text,
        ];
    }

    private function timeout(): int
    {
        return max(1, (int) config('web_research.fetch_timeout', 8));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
            ],
        ];
    }

    private function isHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && $parts['host'] !== '';
    }

    private function isHtmlContentType(?string $contentType): bool
    {
        if ($contentType === null || trim($contentType) === '') {
            // Some servers omit the header; accept and rely on text extraction.
            return true;
        }

        $contentType = strtolower($contentType);

        return str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml')
            || str_contains($contentType, 'text/plain');
    }

    private function extractText(string $html, int $maxChars): string
    {
        $html = (string) preg_replace('/<!--.*?-->/s', ' ', $html);
        $html = (string) preg_replace(
            '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is',
            ' ',
            $html,
        );

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        return mb_substr($text, 0, $maxChars);
    }

    private function extractTitle(string $html): string
    {
        if (! preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $matches)) {
            return '';
        }

        $title = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $title));
    }
}