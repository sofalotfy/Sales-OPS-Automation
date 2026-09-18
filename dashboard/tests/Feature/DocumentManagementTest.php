<?php

namespace Tests\Feature;

use App\Livewire\DocumentsTable;
use App\Livewire\EditDocument;
use App\Livewire\UploadDocument;
use App\Support\UpstreamSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Support\UpstreamStubs;
use Tests\TestCase;

class DocumentManagementTest extends TestCase
{
    public function test_documents_index_requires_authentication(): void
    {
        $this->get(route('documents.index'))->assertRedirect(route('login.show'));
    }

    public function test_documents_index_renders_loaded_rows(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDocumentList([
            UpstreamStubs::document(id: 'a', title: 'Playbook', status: 'ready'),
            UpstreamStubs::document(id: 'b', title: 'Triage notes', status: 'processing'),
        ]);

        Livewire::test(DocumentsTable::class)
            ->assertOk()
            ->assertSee('Playbook')
            ->assertSee('Triage notes')
            ->assertSee('View')
            ->assertSee('Download');
    }

    public function test_documents_index_empty_state(): void
    {
        $this->signIn();
        UpstreamStubs::fakeBlankRag();

        Livewire::test(DocumentsTable::class)
            ->assertOk()
            ->assertSee('No documents');
    }

    public function test_upload_posts_multipart_and_clears_success(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDocumentCreate();

        Livewire::test(UploadDocument::class)
            ->set('title', 'New manual')
            ->set('source', 'manuals')
            ->set('file', UploadedFile::fake()->create('manual.md', 10, 'text/markdown'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('title', '')
            ->assertSet('source', '');
    }

    public function test_upload_surfaces_duplicate_inline(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDocument409();

        Livewire::test(UploadDocument::class)
            ->set('title', 'Dup')
            ->set('file', UploadedFile::fake()->create('dup.md', 10, 'text/markdown'))
            ->call('save')
            ->assertSee('already exists');
    }

    public function test_upload_validates_fields_client_side(): void
    {
        $this->signIn();
        Http::fake();

        Livewire::test(UploadDocument::class)
            ->call('save')
            ->assertHasErrors(['file']);
    }

    public function test_edit_prefills_and_patches_metadata(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDocumentList([
            UpstreamStubs::document(id: 'doc-1', title: 'Old title', source: 'old'),
        ]);
        UpstreamStubs::fakeDocumentUpdate(id: 'doc-1', title: 'New title');

        Livewire::test(EditDocument::class, ['documentId' => 'doc-1'])
            ->assertSet('title', 'Old title')
            ->set('title', 'New title')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_delete_sends_delete_request(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDocumentDelete('doc-1');

        Livewire::test(DocumentsTable::class)
            ->call('delete', 'doc-1')
            ->assertOk();
    }

    public function test_rag_401_during_index_forces_relogin(): void
    {
        $this->signIn();
        UpstreamStubs::fakeUpstreamUnauthorized();

        Livewire::test(DocumentsTable::class)
            ->assertRedirect(route('login.show'));
    }
}