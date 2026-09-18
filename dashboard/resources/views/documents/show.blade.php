@extends('layouts.app')

@section('title', $document['title'] ?? 'Document')

@section('content')
    <div class="mb-6">
        <a href="{{ route('documents.index') }}" class="text-sm font-medium text-slate-500 hover:text-slate-700">
            &larr; Back to documents
        </a>

        <div class="mt-3 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">
                    {{ $document['title'] ?? 'Document not found' }}
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $document['file_type'] ?? '' }}
                    @if ($document)
                        &middot; {{ $document['char_count'] }} chars
                        &middot; {{ $document['chunk_count'] }} chunks
                    @endif
                </p>
            </div>
            @if ($document && ($document['original_available'] ?? false))
                <a
                    href="{{ route('documents.download', $document['document_id']) }}"
                    class="whitespace-nowrap rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500"
                >
                    Download original
                </a>
            @elseif ($document)
                <p class="text-xs text-slate-400" title="This document was ingested before original files were retained.">
                    Original unavailable
                </p>
            @endif
        </div>
    </div>

    <x-upstream-error message="{{ $error }}" />

    @if ($document)
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-slate-500">Source</dt>
                    <dd class="mt-0.5 font-medium text-slate-900">{{ $document['source'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Status</dt>
                    <dd class="mt-0.5"><x-status-badge :status="$document['status'] ?? 'unknown'" /></dd>
                </div>
                <div>
                    <dt class="text-slate-500">File type</dt>
                    <dd class="mt-0.5 font-medium text-slate-900">{{ $document['file_type'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Document id</dt>
                    <dd class="mt-0.5 truncate font-mono text-xs text-slate-600">{{ $document['document_id'] }}</dd>
                </div>
            </dl>

            <h2 class="mt-6 text-base font-semibold text-slate-900">Content preview</h2>
            <p class="mt-1 text-xs text-slate-400">
                Reconstructed text extracted from the document. Use Download original for the uploaded file.
            </p>
            <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap rounded-md bg-slate-50 p-4 text-sm leading-relaxed text-slate-800">{{ $document['content'] }}</pre>
        </div>
    @endif
@endsection