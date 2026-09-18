<?php

namespace App\WebResearch;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bounded page fetcher for the AI research agent (feature 010, research R3).
 *
 * Retrieves a kept source page and returns its extracted visible text, or
 * `null` on ANY failure (unsupported scheme, connection/timeout, non-2xx,
 * non-HTML content, empty text). The agent skips a `null` source rather than
 * aborting the run (US2 acceptance 2).
 *
 * Bounds (config/web_research.php):
 *  - `fetch_timeout`   seconds;
 *  - `fetch_max_bytes` downloaded body cap;
 *  - `source_max_chars` extracted-text cap.
 *
 * Fetched content is treated strictly as data — it is never executed or
 * interpreted as instructions (FR-009).
 */
class PageFetcher
{
    /**
     * @return array{title: string, url: string, text: string}|null
     */
    public function fetch(string $url): ?array
    {
        if (! $this->isHttpUrl($url)) {
            return null;
        }

        $timeout = max(1, (int) config('web_research.fetch_timeout', 8));
        $maxBytes = max(1024, (int) config('web_research.fetch_max_bytes', 200000));
        $maxChars = max(1, (int) config('web_research.source_max_chars', 8000));

        try {
            $response = Http::withHeaders([
                'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.1',
            ])
                ->timeout($timeout)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['http', 'https'],
                    ],
                ])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

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
