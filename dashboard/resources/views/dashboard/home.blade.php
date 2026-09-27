@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $byStatus = is_array($stats['by_status'] ?? null) ? $stats['by_status'] : [];
        $byClassification = is_array($stats['by_classification'] ?? null) ? $stats['by_classification'] : [];
        $byClassificationNoSignal = is_array($stats['by_classification_no_signal'] ?? null) ? $stats['by_classification_no_signal'] : [];
        $inFlightStatuses = ['queued', 'processing', 'researching', 'scope_check', 'scoring'];
        $succeededCount = (int) ($byStatus['succeeded'] ?? 0);
        $failedCount = (int) ($byStatus['failed'] ?? 0);
        $inFlightCount = 0;
        foreach ($inFlightStatuses as $inFlightStatus) {
            $inFlightCount += (int) ($byStatus[$inFlightStatus] ?? 0);
        }
        $refusalCount = (int) ($stats['refusals'] ?? 0);
        // A final_score of 0 means the factor found no signal, not that the lead
        // is bad, so runs are reported as scored / no-signal rather than folded
        // into a single average that reads like lead quality.
        $scoredCount = (int) ($stats['scored'] ?? 0);
        $noSignalCount = (int) ($stats['no_signal'] ?? 0);
        $avgScoredScore = $stats['avg_scored_score'] ?? null;
        $scoredShare = ($total ?? 0) > 0 ? (int) round($scoredCount / $total * 100) : 0;
        $failedOnPage = array_values(array_filter(
            $results,
            fn (array $result): bool => ($result['status'] ?? null) === 'failed',
        ));
        $failedOffPage = max(0, $failedCount - count($failedOnPage));
    @endphp

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Dashboard</h1>
            <p class="mt-1 text-sm text-slate-500">Every inquiry sent through the triage pipeline.</p>
        </div>
        <a href="{{ route('classification.index') }}" class="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">
            View all runs
        </a>
    </div>

    <x-upstream-error message="{{ $statsError ?? '' }}" />

    @if ($stats === null)
        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
            <p class="text-sm text-slate-600">Run statistics are unavailable while the inquiry handler is unreachable.</p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Total runs</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $total ?? 0 }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Succeeded</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-600">{{ $succeededCount }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Failed</p>
                <p class="mt-1 text-2xl font-semibold text-red-600">{{ $failedCount }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">In flight</p>
                <p class="mt-1 text-2xl font-semibold text-sky-600">{{ $inFlightCount }}</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Usable signal</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $scoredCount }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    {{ $scoredShare }}% of {{ $total ?? 0 }} runs
                    @if ($noSignalCount > 0)
                        &middot; {{ $noSignalCount }} scored 0
                    @endif
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Avg score (scored runs)</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $avgScoredScore === null ? '—' : number_format((float) $avgScoredScore, 2) }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    @if ($noSignalCount > 0)
                        Excludes {{ $noSignalCount }} zero-signal {{ \Illuminate\Support\Str::plural('run', $noSignalCount) }}
                    @else
                        Across every scored run
                    @endif
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Refusals</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $refusalCount }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Classified</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ array_sum($byClassification) }}</p>
            </div>
        </div>

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">Classification mix</h2>

                @if ($byClassification === [])
                    <p class="mt-3 text-sm text-slate-500">No runs have been classified yet.</p>
                @else
                    @php($classifiedTotal = max(1, array_sum($byClassification)))
                    <ul class="mt-4 space-y-3">
                        @foreach ($byClassification as $classification => $count)
                            @php($verdictNoSignal = (int) ($byClassificationNoSignal[$classification] ?? 0))
                            <li>
                                <div class="flex items-center justify-between gap-3">
                                    <x-classification-badge :classification="$classification" />
                                    <span class="text-sm text-slate-600">
                                        {{ $count }} &middot; {{ (int) round($count / $classifiedTotal * 100) }}%
                                        @if ($verdictNoSignal > 0)
                                            <span class="text-slate-400">&middot; {{ $verdictNoSignal }} scored 0</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-sky-500" style="width: {{ max(2, (int) round($count / $classifiedTotal * 100)) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">Needs attention</h2>

                @if ($failedOnPage === [])
                    <p class="mt-3 text-sm text-slate-500">
                        @if ($failedCount === 0)
                            No runs have failed.
                        @else
                            No failed runs among the newest {{ count($results) }}.
                        @endif
                    </p>
                @else
                    <ul class="mt-3 space-y-3">
                        @foreach ($failedOnPage as $failed)
                            <li class="rounded-lg border border-red-100 bg-red-50 px-4 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm text-red-800">
                                        {{ $failed['error'] ?? 'The run failed without recording an error.' }}
                                    </p>
                                    <a href="{{ route('classification.show', $failed['id']) }}" class="shrink-0 text-sm font-medium text-red-700 hover:text-red-900">View</a>
                                </div>
                                <p class="mt-1 text-xs text-red-600">
                                    {{ \Illuminate\Support\Str::limit($failed['inquiry_message'] ?? '', 80) }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($failedOffPage > 0)
                    <p class="mt-3 text-xs text-slate-500">
                        {{ $failedOffPage }} older failed {{ \Illuminate\Support\Str::plural('run', $failedOffPage) }} —
                        <a href="{{ route('classification.index') }}" class="font-medium text-sky-600 hover:text-sky-500">view all runs</a>.
                    </p>
                @endif
            </div>
        </div>
    @endif

    <div class="mt-8">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold text-slate-900">Recent runs</h2>
            <a href="{{ route('classification.index') }}" class="text-sm font-medium text-sky-600 hover:text-sky-500">
                View all
            </a>
        </div>

        <x-upstream-error message="{{ $resultsError ?? '' }}" />

        @if (! empty($resultsError))
            <p class="text-sm text-slate-500">The run log is unavailable while the inquiry handler is unreachable.</p>
        @elseif (empty($results))
            <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <p class="text-sm text-slate-600">No inquiries have been sent yet.</p>
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">When</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Classification</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Score</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-slate-500">Inquiry</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium text-slate-500">View</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($results as $result)
                            @php($runStatus = (string) ($result['status'] ?? 'unknown'))
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                                    {{ \Illuminate\Support\Str::limit(\Illuminate\Support\Carbon::parse($result['created_at'])->diffForHumans(), 20) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @php($inProgress = in_array($runStatus, ['queued', 'processing', 'researching', 'scope_check', 'scoring'], true))
                                    <span class="text-xs {{ $inProgress ? 'text-sky-600' : ($runStatus === 'failed' ? 'text-red-600' : 'text-slate-500') }}">{{ ucfirst($runStatus) }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <x-classification-badge :classification="$result['classification'] ?? null" />
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-900">{{ $result['final_score'] ?? '—' }}</td>
                                <td class="max-w-xs px-4 py-3 text-slate-600">{{ $result['inquiry_message'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('classification.show', $result['id']) }}" class="text-sky-600 hover:text-sky-500">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
