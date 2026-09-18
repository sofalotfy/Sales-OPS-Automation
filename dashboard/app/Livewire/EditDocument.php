<?php

namespace App\Livewire;

use App\Services\RagApiClient;
use App\Support\UpstreamSession;
use Livewire\Component;

class EditDocument extends Component
{
    public string $documentId;

    public ?string $title = null;

    public ?string $source = null;

    public bool $success = false;

    public ?string $error = null;

    protected $rules = [
        'title' => 'required|string|min:1|max:512',
        'source' => 'nullable|string|max:255',
    ];

    public function mount(string $documentId, RagApiClient $rag): void
    {
        $this->documentId = $documentId;

        $items = $rag->listDocuments(UpstreamSession::token() ?? '', limit: 200)->json('items', []);
        foreach ($items as $item) {
            if (($item['document_id'] ?? null) === $documentId) {
                $this->title = $item['title'] ?? null;
                $this->source = $item['source'] ?? null;

                return;
            }
        }

        $this->error = 'Document not found. It may have been deleted.';
    }

    public function save(RagApiClient $rag): void
    {
        $this->success = false;
        $this->error = null;

        $this->validate();

        $response = $rag->updateMetadata(
            token: UpstreamSession::token() ?? '',
            documentId: $this->documentId,
            title: $this->title,
            source: $this->source !== '' ? $this->source : null,
        );

        if ($response->status() === 401) {
            $this->error = 'Not authenticated. Please sign in again.';
            $this->redirect(route('login.show'));

            return;
        }

        if ($response->status() === 404) {
            $this->error = 'Document not found. It may have been deleted.';

            return;
        }

        if ($response->clientError() || $response->serverError()) {
            $this->error = $response->json('detail')
                ?? 'The document service rejected the change. Please try again.';

            return;
        }

        $this->success = true;
        $this->dispatch('documents.refresh');
    }

    public function render()
    {
        return view('livewire.edit-document');
    }
}