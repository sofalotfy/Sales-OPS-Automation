@extends('layouts.app')

@section('title', 'Edit document')

@section('content')
    <div class="mb-6">
        <a href="{{ route('documents.index') }}" class="text-sm font-medium text-slate-500 hover:text-slate-700">
            &larr; Back to documents
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">Edit document</h1>
        <p class="mt-1 text-sm text-slate-500">Metadata-only changes — content, chunks, and status are untouched.</p>
    </div>

    <div class="max-w-2xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <livewire:edit-document :document-id="$documentId" />
    </div>
@endsection