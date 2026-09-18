<?php

namespace Tests\Unit;

use App\WebResearch\PageFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bounded page fetcher unit tests (feature 010, US2).
 */
class PageFetcherTest extends TestCase
{
    private PageFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetcher = new PageFetcher();
        config()->set('web_research.fetch_timeout', 8);
        config()->set('web_research.fetch_max_bytes', 200000);
        config()->set('web_research.source_max_chars', 8000);
    }

    public function test_extracts_title_and_visible_text_without_script_or_style(): void
    {
        $html = <<<'HTML'
        <html><head><title>Example Corp — About</title>
        <style>.x{color:red}</style></head>
        <body><script>alert('ignore me')</script>
        <h1>Example Corp</h1><p>We build   payments &amp; tools.</p>
        <!-- a comment --></body></html>
        HTML;

        Http::fake(['https://example.com/about' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        $page = $this->fetcher->fetch('https://example.com/about');

        $this->assertNotNull($page);
        $this->assertSame('Example Corp — About', $page['title']);
        $this->assertSame('https://example.com/about', $page['url']);
        $this->assertStringContainsString('Example Corp', $page['text']);
        $this->assertStringContainsString('payments & tools', $page['text']);
        $this->assertStringNotContainsString('ignore me', $page['text']);
        $this->assertStringNotContainsString('color:red', $page['text']);
    }

    public function test_caps_extracted_text_at_source_max_chars(): void
    {
        config()->set('web_research.source_max_chars', 20);

        Http::fake(['https://example.com' => Http::response('<p>'.str_repeat('a', 500).'</p>', 200, ['Content-Type' => 'text/html'])]);

        $page = $this->fetcher->fetch('https://example.com');

        $this->assertNotNull($page);
        $this->assertSame(20, mb_strlen($page['text']));
    }

    public function test_returns_null_on_non_successful_status(): void
    {
        Http::fake(['https://example.com' => Http::response('nope', 404, ['Content-Type' => 'text/html'])]);

        $this->assertNull($this->fetcher->fetch('https://example.com'));
    }

    public function test_returns_null_on_non_html_content_type(): void
    {
        Http::fake(['https://example.com/file.pdf' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);

        $this->assertNull($this->fetcher->fetch('https://example.com/file.pdf'));
    }

    public function test_returns_null_when_page_has_no_visible_text(): void
    {
        Http::fake(['https://example.com' => Http::response('<html><script>only()</script></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertNull($this->fetcher->fetch('https://example.com'));
    }

    public function test_returns_null_on_connection_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->assertNull($this->fetcher->fetch('https://example.com'));
    }

    public function test_only_http_and_https_urls_are_fetched(): void
    {
        Http::fake();

        $this->assertNull($this->fetcher->fetch('ftp://example.com/file'));
        $this->assertNull($this->fetcher->fetch('javascript:alert(1)'));
        $this->assertNull($this->fetcher->fetch('not-a-url'));

        Http::assertNothingSent();
    }
}
