@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Dashboard</h1>
            <p class="mt-1 text-sm text-slate-500">Overview of the RAG document corpus.</p>
        </div>
        <a href="{{ route('documents.create') }}" class="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">
            Upload document
        </a>
    </div>

    <x-upstream-error message="{{ $error }}" />

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Total documents</p>
            <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $total }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Ready</p>
            <p class="mt-1 text-2xl font-semibold text-emerald-600">{{ $counts['ready'] ?? 0 }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Processing</p>
            <p class="mt-1 text-2xl font-semibold text-amber-600">{{ $counts['processing'] ?? 0 }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Failed</p>
            <p class="mt-1 text-2xl font-semibold text-red-600">{{ $counts['failed'] ?? 0 }}</p>
        </div>
    </div>

    <div class="mt-8">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold text-slate-900">Recent documents</h2>
            <a href="{{ route('documents.index') }}" class="text-sm font-medium text-sky-600 hover:text-sky-500">
                View all
            </a>
        </div>

        @if (! empty($recent))
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Title</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Source</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($recent as $document)
                            @php($id = $document['document_id'] ?? '')
                            <tr>
                                <td class="max-w-xs px-4 py-3">
                                    <a href="{{ route('documents.edit', $id) }}" class="font-medium text-slate-900 hover:text-sky-600">
                                        {{ $document['title'] ?? '(untitled)' }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <x-status-badge :status="$document['status'] ?? 'unknown'" />
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $document['source'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('documents.show', $id) }}" class="text-sky-600 hover:text-sky-500">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif (empty($error))
            <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <p class="text-sm text-slate-600">No documents yet.</p>
                <a href="{{ route('documents.create') }}" class="mt-3 inline-block text-sm font-semibold text-sky-600 hover:text-sky-500">
                    Upload the first document
                </a>
            </div>
        @else
            <p class="text-sm text-slate-500">Recent documents are unavailable while the document service is unreachable.</p>
        @endif
    </div>
@endsection