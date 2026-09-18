@extends('layouts.app')

@section('title', 'Documents')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-900">Documents</h1>
        <a href="{{ route('documents.create') }}" class="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">
            Upload document
        </a>
    </div>

    <livewire:documents-table />
@endsection