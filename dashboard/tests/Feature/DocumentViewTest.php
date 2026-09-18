<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs;
use Tests\TestCase;

class DocumentViewTest extends TestCase
{
    public function test_view_and_download_require_authentication(): void
    {
        $this->get(route('documents.show', 'doc-1'))
            ->assertRedirect(route('login.show'));
        $this->get(route('documents.download', 'doc-1'))
            ->assertRedirect(route('login.show'));
    }

    public function test_view_renders_extracted_content_preview(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDocumentContent('doc-1');

        $this->get(route('documents.show', 'doc-1'))
            ->assertOk()
            ->assertSee('Sales Playbook 2026')
            ->assertSee('extracted content preview')
            ->assertSee('Download original')
            ->assertSee('Back to documents');
    }

    public function test_view_401_forces_relogin(): void
    {
        $this->signIn();
        UpstreamStubs::fakeUpstreamUnauthorized();

        $this->get(route('documents.show', 'doc-1'))
            ->assertRedirect(route('login.show'));
    }

    public function test_view_not_found_shows_error_state(): void
    {
        $this->signIn();
        Http::fake([
            UpstreamStubs::ragUrl('/documents/doc-1/content') => Http::response(
                ['detail' => 'Document not found.'],
                404,
            ),
        ]);

        $this->get(route('documents.show', 'doc-1'))
            ->assertOk()
            ->assertSee('Document not found.');
    }

    public function test_view_hides_download_when_original_unavailable(): void
    {
        $this->signIn();
        Http::fake([
            UpstreamStubs::ragUrl('/documents/doc-1/content') => Http::response(
                UpstreamStubs::documentContent(id: 'doc-1', originalAvailable: false),
                200,
            ),
        ]);

        $this->get(route('documents.show', 'doc-1'))
            ->assertOk()
            ->assertSee('Original unavailable')
            ->assertDontSee('/documents/doc-1/download');
    }

    public function test_download_streams_the_original_file(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDocumentFile('doc-1', bytes: 'original-bytes-123', filename: 'playbook.md');

        $response = $this->get(route('documents.download', 'doc-1'));
        $response->assertOk()->assertDownload('playbook.md');
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
    }

    public function test_download_401_forces_relogin(): void
    {
        $this->signIn();
        UpstreamStubs::fakeUpstreamUnauthorized();

        $this->get(route('documents.download', 'doc-1'))
            ->assertRedirect(route('login.show'));
    }
}