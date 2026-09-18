<?php

namespace App\Livewire;

use App\Services\RagApiClient;
use App\Support\UpstreamSession;
use Livewire\Component;

class DocumentsTable extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $documents = [];

    public int $total = 0;

    public ?string $error = null;

    public ?string $lastDeleted = null;

    public function mount(RagApiClient $rag): void
    {
        $this->load($rag);
    }

    public function refresh(RagApiClient $rag): void
    {
        $this->load($rag);
    }

    private function load(RagApiClient $rag): void
    {
        $this->error = null;

        $response = $rag->listDocuments(UpstreamSession::token() ?? '');
        if ($response->status() === 401) {
            $this->error = 'Not authenticated. Please sign in again.';
            $this->redirect(route('login.show'));

            return;
        }
        if ($response->serverError() || $response->clientError()) {
            $this->error = $response->json('detail')
                ?? 'The document service is unavailable. Please try again.';

            return;
        }

        $this->documents = $response->json('items', []);
        $this->total = (int) $response->json('total', count($this->documents));
    }

    public function delete(string $documentId, RagApiClient $rag): void
    {
        $response = $rag->deleteDocument(UpstreamSession::token() ?? '', $documentId);

        if ($response->status() === 401) {
            $this->error = 'Not authenticated. Please sign in again.';
            $this->redirect(route('login.show'));

            return;
        }
        if ($response->failed()) {
            $this->error = $response->json('detail')
                ?? 'Could not delete the document. Please try again.';

            return;
        }

        $this->lastDeleted = $documentId;
        $this->load($rag);
    }

    /** True while any row is still processing — drives the polling toggle. */
    public function hasProcessing(): bool
    {
        foreach ($this->documents as $document) {
            if (($document['status'] ?? null) === 'processing') {
                return true;
            }
        }

        return false;
    }

    public function render()
    {
        return view('livewire.documents-table');
    }
}