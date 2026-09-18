<form wire:submit="save" class="space-y-4">
    <x-upstream-error message="{{ $error }}" />

    @if ($success)
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            Changes saved.
        </div>
    @endif

    <div>
        <label for="edit-title" class="block text-sm font-medium text-slate-700">Title</label>
        <input
            id="edit-title"
            type="text"
            wire:model="title"
            maxlength="512"
            class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
        >
        @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="edit-source" class="block text-sm font-medium text-slate-700">Source</label>
        <input
            id="edit-source"
            type="text"
            wire:model="source"
            maxlength="255"
            placeholder="e.g. sales-ops"
            class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
        >
        <p class="mt-1 text-xs text-slate-400">Leave blank to clear the source.</p>
        @error('source') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <button type="submit" class="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">
        Save changes
    </button>
</form>