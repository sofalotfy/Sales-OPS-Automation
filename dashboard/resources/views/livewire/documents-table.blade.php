<div {{ $attributes }}>
    <x-upstream-error message="{{ $error }}" />

    @if (! empty($documents))
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm" @if ($this->hasProcessing()) wire:poll.5s="refresh" @endif>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Title</th>
                        <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                        <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Source</th>
                        <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Chunks</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium text-slate-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($documents as $document)
                        @php($id = $document['document_id'] ?? '')
                        <tr>
                            <td class="max-w-xs px-4 py-3">
                                <a href="{{ route('documents.edit', $id) }}" class="font-medium text-slate-900 hover:text-sky-600">
                                    {{ $document['title'] ?? '(untitled)' }}
                                </a>
                                <div class="mt-1 line-clamp-1 text-xs text-slate-400">
                                    {{ $document['file_type'] ?? '' }} — {{ $document['updated_at'] ?? '' }}
                                </div>
                                @if (($document['status'] ?? null) === 'failed' && ! empty($document['error']))
                                    <p class="mt-1 text-xs text-red-600">{{ $document['error'] }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-status-badge :status="$document['status'] ?? 'unknown'" />
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $document['source'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $document['chunk_count'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-3">
                                    <a href="{{ route('documents.show', $id) }}" class="text-sky-600 hover:text-sky-500">View</a>
                                    <a href="{{ route('documents.edit', $id) }}" class="text-sky-600 hover:text-sky-500">Edit</a>
                                    @if ($document['original_available'] ?? false)
                                        <a href="{{ route('documents.download', $id) }}" class="text-slate-600 hover:text-slate-500">Download</a>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="delete('{{ $id }}')"
                                        wire:confirm="Delete this document? This cannot be undone."
                                        class="text-red-600 hover:text-red-500"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
            <p class="text-sm text-slate-600">No documents yet.</p>
            <a href="{{ route('documents.create') }}" class="mt-3 inline-block text-sm font-semibold text-sky-600 hover:text-sky-500">
                Upload the first document
            </a>
        </div>
    @endif

    <div class="mt-3 text-xs text-slate-500">
        {{ $total }} document{{ $total === 1 ? '' : 's' }}
        @if ($lastDeleted)
            · deleted {{ $lastDeleted }}
        @endif
    </div>
</div>