@extends('layouts.app')

@section('title', 'Industry sectors')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-slate-900">Industry sectors</h1>
        <p class="mt-1 text-sm text-slate-500">
            Manage the sector catalog the inquiry handler scores every inquiry against.
            Changes apply to the next triage run immediately.
        </p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <x-upstream-error message="{{ $errors->first('sectors') }}" />

    <div class="max-w-3xl">
        <h2 class="mb-3 text-base font-semibold text-slate-900">Add sector</h2>

        @if (! empty($error))
            <p class="mb-4 text-sm text-red-600">{{ $error }}</p>
        @else
            <form method="POST" action="{{ route('sectors.store') }}" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                @csrf

                <div class="grid grid-cols-1 gap-4 px-4 py-4 sm:grid-cols-3 sm:items-end">
                    <div>
                        <label for="name" class="mb-1 block text-sm font-medium text-slate-700">Name</label>
                        <input
                            id="name"
                            type="text"
                            name="name"
                            value="{{ old('name') }}"
                            maxlength="100"
                            required
                            class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
                        >
                    </div>
                    <div>
                        <label for="rating" class="mb-1 block text-sm font-medium text-slate-700">Rating (0–100)</label>
                        <input
                            id="rating"
                            type="number"
                            name="rating"
                            value="{{ old('rating') }}"
                            min="0"
                            max="100"
                            required
                            class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
                        >
                    </div>
                    <div class="sm:justify-self-end">
                        <button type="submit" class="w-full rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500 sm:w-auto">
                            Add sector
                        </button>
                    </div>
                </div>
            </form>
        @endif

        <p class="mt-2 px-1 text-xs text-slate-400">Names are case-insensitively unique and limited to 100 characters.</p>
    </div>

    <div class="mt-10">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold text-slate-900">Catalog</h2>
            @if (! empty($sectors))
                <span class="text-sm text-slate-500">{{ count($sectors) }} sectors</span>
            @endif
        </div>

        @if (! empty($error))
            <p class="text-sm text-red-600">{{ $error }}</p>
        @elseif (empty($sectors))
            <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <p class="text-sm text-slate-600">No sectors in the catalog yet. Add one above.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($sectors as $sector)
                    <div class="flex flex-col gap-3 overflow-hidden rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center">
                        <form method="POST" action="{{ route('sectors.update', $sector['id']) }}" class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                            @csrf
                            @method('PUT')

                            <input
                                type="text"
                                name="name"
                                value="{{ $sector['name'] }}"
                                maxlength="100"
                                required
                                class="block flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
                            >
                            <input
                                type="number"
                                name="rating"
                                value="{{ $sector['rating'] }}"
                                min="0"
                                max="100"
                                required
                                class="block w-28 rounded-md border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
                            >

                            <button type="submit" class="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">
                                Save
                            </button>
                        </form>

                        <form method="POST" action="{{ route('sectors.destroy', $sector['id']) }}" onsubmit="return confirm('Delete sector “{{ $sector['name'] }}”? This affects future triage runs only.');">
                            @csrf
                            @method('DELETE')

                            <button type="submit" class="w-full rounded-md border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50 sm:w-auto">
                                Delete
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection