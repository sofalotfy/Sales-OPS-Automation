<?php

namespace Tests\Feature;

use App\Livewire\DocumentsTable;
use Livewire\Livewire;
use Tests\Feature\Support\UpstreamStubs;
use Tests\TestCase;

class StatusVisibilityTest extends TestCase
{
    public function test_mixed_statuses_render_matching_badges(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDocumentList([
            UpstreamStubs::document(id: 'p', title: 'Calc sheet', status: 'processing'),
            UpstreamStubs::document(id: 'r', title: 'Playbook', status: 'ready'),
            UpstreamStubs::document(id: 'f', title: 'Broken file', status: 'failed'),
        ]);

        Livewire::test(DocumentsTable::class)
            ->assertSee('Processing')
            ->assertSee('Ready')
            ->assertSee('Failed');
    }

    public function test_failed_row_exposes_error_summary(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDocumentList([
            UpstreamStubs::document(
                id: 'f',
                title: 'Broken file',
                status: 'failed',
                error: 'Unsupported file type: .exe',
            ),
        ]);

        Livewire::test(DocumentsTable::class)
            ->assertSee('Broken file')
            ->assertSee('Unsupported file type: .exe');
    }

    public function test_status_change_is_reflected_after_poll_refresh(): void
    {
        $this->signIn();

        $status = 'processing';
        $this->stubDocumentStatus(static function () use (&$status) {
            return $status;
        });

        $component = Livewire::test(DocumentsTable::class)
            ->assertSee('Processing');

        $status = 'ready';
        $component
            ->call('refresh')
            ->assertSee('Ready')
            ->assertDontSee('Processing');
    }

    public function test_poll_attribute_present_while_processing(): void
    {
        $this->signIn();
        $this->stubDocumentStatus(fn () => 'processing');

        $this->assertStringContainsString('wire:poll.5s', Livewire::test(DocumentsTable::class)->html());
    }

    public function test_poll_attribute_absent_when_all_ready(): void
    {
        $this->signIn();
        $this->stubDocumentStatus(fn () => 'ready');

        $this->assertStringNotContainsString('wire:poll.5s', Livewire::test(DocumentsTable::class)->html());
    }

    /** Fake GET /documents so the served status can change between renders. */
    private function stubDocumentStatus(callable $status): void
    {
        \Illuminate\Support\Facades\Http::fake([
            UpstreamStubs::ragUrl('/documents*') => function () use ($status) {
                return \Illuminate\Support\Facades\Http::response([
                    'items' => [
                        UpstreamStubs::document(id: 'p', title: 'Ingest', status: $status()),
                    ],
                    'total' => 1,
                    'limit' => 50,
                    'offset' => 0,
                ], 200);
            },
        ]);
    }
}