<?php

namespace App\Livewire;

use App\Services\RagApiClient;
use App\Support\UpstreamSession;
use Livewire\Component;
use Livewire\WithFileUploads;

class UploadDocument extends Component
{
    use WithFileUploads;

    public ?string $title = null;

    public ?string $source = null;

    /** @var \Illuminate\Http\UploadedFile|null */
    public $file = null;

    public bool $success = false;

    public ?string $error = null;

    protected $rules = [
        'title' => 'nullable|string|max:512',
        'source' => 'nullable|string|max:255',
        'file' => 'required|file|max:10240',
    ];

    public function save(RagApiClient $rag): void
    {
        $this->success = false;
        $this->error = null;

        $this->validate();

        if ($this->file === null) {
            return;
        }

        $response = $rag->createDocument(
            token: UpstreamSession::token() ?? '',
            filename: $this->file->getClientOriginalName(),
            content: $this->file->get(),
            title: $this->title,
            source: $this->source !== '' ? $this->source : null,
        );

        if ($response->status() === 401) {
            $this->error = 'Not authenticated. Please sign in again.';
            $this->redirect(route('login.show'));

            return;
        }

        if ($response->status() === 409) {
            $this->error = str_replace(
                '. ',
                '. ',
                (string) ($response->json('detail') ?? 'A document with this content already exists.'),
            );

            return;
        }

        if ($response->clientError() || $response->serverError()) {
            $this->error = $response->json('detail')
                ?? 'The document service could not process this upload. Please try again.';

            return;
        }

        $this->success = true;
        $this->reset('title', 'source');
        $this->resetFile();
        $this->dispatch('documents.refresh');
    }

    private function resetFile(): void
    {
        $this->file = null;
    }

    public function render()
    {
        return view('livewire.upload-document');
    }
}