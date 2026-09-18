@extends('layouts.app')

@section('title', 'Inquiry classification')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-slate-900">Inquiry classification</h1>
        <p class="mt-1 text-sm text-slate-500">
            Tune the scoring factors and review the classification history of the inquiry handler.
        </p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="max-w-3xl">
        <h2 class="mb-3 text-base font-semibold text-slate-900">Factor weights</h2>

        <x-upstream-error message="{{ $weightsError ?? $errors->first('weights') }}" />

        @if (empty($factors))
            @if (empty($weightsError))
                <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                    <p class="text-sm text-slate-600">No factors are registered on the inquiry handler yet.</p>
                </div>
            @else
                <p class="text-sm text-slate-500">Factor weights are unavailable while the inquiry handler is unreachable.</p>
            @endif
        @else
            <form method="POST" action="{{ route('classification.update') }}" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                @csrf
                @method('PUT')

                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Factor</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Weight</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Source</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($factors as $factor)
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-900">{{ $factor['name'] }}</td>
                                <td class="px-4 py-3">
                                    <input
                                        type="number"
                                        name="weights[{{ $factor['name'] }}]"
                                        value="{{ $factor['weight'] }}"
                                        min="0"
                                        step="0.01"
                                        class="block w-28 rounded-md border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    @if (($factor['source'] ?? '') === 'stored')
                                        <span class="inline-flex rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700">stored</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">default</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="border-t border-slate-200 px-4 py-3">
                    <button type="submit" class="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">
                        Save weights
                    </button>
                </div>
            </form>
        @endif
    </div>

    <div class="mt-10">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold text-slate-900">Recent classifications</h2>
            @if ($total > 0)
                <span class="text-sm text-slate-500">{{ $total }} total</span>
            @endif
        </div>

        <x-upstream-error message="{{ $resultsError }}" />

        @if (! empty($resultsError))
            <p class="text-sm text-slate-500">The classification log is unavailable while the inquiry handler is unreachable.</p>
        @elseif (empty($results))
            <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <p class="text-sm text-slate-600">No inquiries classified yet.</p>
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">When</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Classification</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Score</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Inquiry</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Reasoning</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium text-slate-500">View</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($results as $result)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                                    {{ \Illuminate\Support\Str::limit(\Illuminate\Support\Carbon::parse($result['created_at'])->diffForHumans(), 20) }}
                                </td>
                                <td class="px-4 py-3">
                                    <x-classification-badge :classification="$result['classification'] ?? 'low'" />
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-900">{{ $result['final_score'] ?? 0 }}</td>
                                <td class="max-w-xs px-4 py-3 text-slate-600">{{ $result['inquiry_message'] ?? '—' }}</td>
                                <td class="max-w-xs px-4 py-3 text-slate-600">{{ $result['reasoning'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('classification.show', $result['id']) }}" class="text-sky-600 hover:text-sky-500">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($pageCount > 1)
                <div class="mt-4 flex items-center justify-between text-sm">
                    <span class="text-slate-500">Page {{ $page }} of {{ $pageCount }}</span>
                    <div class="flex gap-3">
                        @if ($page > 1)
                            <a href="{{ route('classification.index', ['page' => $page - 1]) }}" class="font-medium text-sky-600 hover:text-sky-500">&larr; Previous</a>
                        @endif
                        @if ($page < $pageCount)
                            <a href="{{ route('classification.index', ['page' => $page + 1]) }}" class="font-medium text-sky-600 hover:text-sky-500">Next &rarr;</a>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    </div>
@endsection