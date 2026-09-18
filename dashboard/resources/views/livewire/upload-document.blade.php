<form wire:submit="save" class="space-y-4">
    <x-upstream-error message="{{ $error }}" />

    @if ($success)
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            Document uploaded. It appears in the list as soon as it is indexed.
            <a href="{{ route('documents.index') }}" class="font-semibold underline hover:no-underline">Go to documents</a>
        </div>
    @endif

    <div>
        <label for="upload-file" class="block text-sm font-medium text-slate-700">File (.md, .txt, .pdf)</label>
        <input
            id="upload-file"
            type="file"
            wire:model="file"
            accept=".md,.txt,.pdf,text/markdown,text/plain,application/pdf"
            class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
        >
        @error('file') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="upload-title" class="block text-sm font-medium text-slate-700">Title</label>
        <input
            id="upload-title"
            type="text"
            wire:model="title"
            maxlength="512"
            class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
        >
        @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="upload-source" class="block text-sm font-medium text-slate-700">Source</label>
        <input
            id="upload-source"
            type="text"
            wire:model="source"
            maxlength="255"
            placeholder="e.g. sales-ops"
            class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
        >
        @error('source') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <button type="submit" class="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">
        Upload
    </button>
</form>