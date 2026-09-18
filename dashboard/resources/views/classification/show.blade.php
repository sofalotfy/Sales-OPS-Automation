@extends('layouts.app')

@section('title', 'Classification result')

@section('content')
    <div class="mb-6">
        <a href="{{ route('classification.index') }}" class="text-sm font-medium text-slate-500 hover:text-slate-700">
            &larr; Back to inquiry classification
        </a>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">Classification #{{ $record['id'] ?? '' }}</h1>
    </div>

    <x-upstream-error message="{{ $error }}" />

    @if (empty($record))
        <p class="text-sm text-slate-500">This classification could not be loaded.</p>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                <div class="mb-4 flex items-center justify-between">
                    <x-classification-badge :classification="$record['classification'] ?? 'low'" />
                    <span class="text-sm text-slate-500">{{ \Illuminate\Support\Carbon::parse($record['created_at'])->diffForHumans() }}</span>
                </div>

                <p class="text-slate-700">{{ $record['inquiry_message'] ?? '' }}</p>

                @php($contactName = trim(($record['first_name'] ?? '').' '.($record['last_name'] ?? '')))
                @if ($contactName !== '' || ! empty($record['email']))
                    <p class="mt-4 text-sm text-slate-500">
                        @if ($contactName !== ''){{ $contactName }}@endif
                        @if (! empty($record['email']))<span class="text-slate-400"> · {{ $record['email'] }}</span>@endif
                    </p>
                @endif

                <div class="mt-6 rounded-lg bg-slate-50 px-4 py-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Reasoning</p>
                    <p class="mt-1 text-sm text-slate-700">{{ $record['reasoning'] ?? '—' }}</p>
                    <p class="mt-3 text-xs font-medium uppercase tracking-wide text-slate-400">Final score</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $record['final_score'] ?? 0 }}</p>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">Factor scores</h2>

                @if (empty($record['factor_scores']))
                    <p class="mt-3 text-sm text-slate-500">No factors contributed to this run.</p>
                @else
                    <table class="mt-3 min-w-full divide-y divide-slate-100 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-medium uppercase tracking-wide text-slate-400">
                                <th scope="col" class="py-2 pr-2">Factor</th>
                                <th scope="col" class="py-2 pr-2">Score</th>
                                <th scope="col" class="py-2 text-right">Weighted</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($record['factor_scores'] as $factorScore)
                                <tr>
                                    <td class="py-2 pr-2 text-slate-700">{{ $factorScore['factor'] }}</td>
                                    <td class="py-2 pr-2 text-slate-600">{{ $factorScore['score'] }}</td>
                                    <td class="py-2 text-right text-slate-600">{{ $factorScore['weighted'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs text-slate-400">Weight {{ $factorScore['weight'] ?? '' }}</p>
                @endif

                <h2 class="mt-6 text-base font-semibold text-slate-900">Dropped factors</h2>
                @if (empty($record['dropped_factors']))
                    <p class="mt-2 text-sm text-slate-500">None.</p>
                @else
                    <ul class="mt-2 space-y-1">
                        @foreach ($record['dropped_factors'] as $dropped)
                            <li class="text-sm text-slate-600">{{ $dropped }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">System prompt</h2>

            @php($systemPrompt = (string) ($record['system_prompt'] ?? ''))
            @if ($systemPrompt === '')
                <p class="mt-3 text-sm text-slate-500">System prompt was not recorded for this run.</p>
            @else
                <p class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ $systemPrompt }}</p>
            @endif
        </div>

        @php($webResearch = is_array($record['web_research'] ?? null) ? $record['web_research'] : null)
        @if (is_array($webResearch))
            <div class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-slate-900">Web research</h2>
                    @if (! empty($record['web_research_outcome']))
                        @php($outcomeColor = match ($record['web_research_outcome']) {
                            'decline' => 'text-red-600',
                            'indeterminate' => 'text-amber-600',
                            default => 'text-emerald-600',
                        })
                        <span class="text-sm capitalize {{ $outcomeColor }}">{{ $record['web_research_outcome'] }}</span>
                    @endif
                </div>

                @if (! empty($record['web_research_reason']))
                    <p class="mb-4 text-sm text-slate-600">{{ $record['web_research_reason'] }}</p>
                @endif

                @php($criteria = is_array($webResearch['criteria'] ?? null) ? $webResearch['criteria'] : null)
                @if (is_array($criteria))
                    <div class="mb-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-500">
                        @if (is_array($criteria['company'] ?? null))
                            <span>Company: <span class="text-slate-700">{{ $criteria['company']['name'] ?? '' }}</span>@if (! empty($criteria['company']['country_region'])) <span class="text-slate-400">· {{ $criteria['company']['country_region'] }}</span>@endif</span>
                        @endif
                        @if (is_array($criteria['person'] ?? null))
                            <span>Person: <span class="text-slate-700">{{ $criteria['person']['first_name'] ?? '' }} {{ $criteria['person']['last_name'] ?? '' }}</span>@if (! empty($criteria['person']['company_context'])) <span class="text-slate-400">· {{ $criteria['person']['company_context'] }}</span>@endif</span>
                        @endif
                    </div>
                @endif

                @php($findings = is_array($webResearch['findings'] ?? null) ? $webResearch['findings'] : [])
                @php($summary = trim((string) ($findings['summary'] ?? '')))
                @php($sources = is_array($findings['sources'] ?? null) ? $findings['sources'] : [])
                @php($limitations = trim((string) ($findings['limitations'] ?? '')))
                @php($findingOutcome = (string) ($findings['outcome'] ?? ''))
                @php($uncertain = (bool) ($findings['uncertain'] ?? false))
                @php($companyFindings = is_array($findings['company'] ?? null) ? $findings['company'] : [])
                @php($personFindings = is_array($findings['person'] ?? null) ? $findings['person'] : [])

                @if ($uncertain)
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Best-effort research</p>
                        <p class="mt-1 text-sm text-amber-800">Sources were scarce or the name matched more than one entity, so some details may be inaccurate or incomplete. Verify before relying on these findings.</p>
                    </div>
                @endif

                @if ($summary !== '' || $sources !== [])
                    <div class="rounded-lg border border-slate-100 bg-slate-50 px-4 py-3">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Research summary</p>
                            @if ($findingOutcome !== '')
                                @php($findingColor = match ($findingOutcome) {
                                    'completed' => 'text-emerald-600',
                                    'partial' => 'text-amber-600',
                                    'indeterminate' => 'text-red-600',
                                    default => 'text-slate-500',
                                })
                                <span class="text-xs font-medium {{ $findingColor }}">{{ ucfirst(str_replace('_', ' ', $findingOutcome)) }}</span>
                            @endif
                        </div>
                        @if ($summary !== '')
                            <p class="mt-1 text-sm text-slate-700">{{ $summary }}</p>
                        @endif
                        @if ($limitations !== '')
                            <p class="mt-2 text-xs text-amber-600">{{ $limitations }}</p>
                        @endif
                    </div>

                    <div class="mt-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Sources</p>
                        @if ($sources === [])
                            <p class="mt-1 text-sm text-slate-500">No sources were retained for this summary.</p>
                        @else
                            <ul class="mt-2 space-y-2">
                                @foreach ($sources as $source)
                                    @php($sourceUrl = is_array($source) ? (string) ($source['url'] ?? '') : '')
                                    @php($sourceTitle = is_array($source) ? trim((string) ($source['title'] ?? '')) : '')
                                    <li class="text-sm">
                                        @if ($sourceUrl !== '')
                                            <a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-indigo-600 hover:text-indigo-800">{{ $sourceTitle !== '' ? $sourceTitle : $sourceUrl }}</a>
                                        @elseif ($sourceTitle !== '')
                                            <span class="font-medium text-slate-700">{{ $sourceTitle }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @elseif ($companyFindings !== [] || $personFindings !== [])
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach ([['label' => 'Company', 'data' => $companyFindings], ['label' => 'Person', 'data' => $personFindings]] as $section)
                            <div class="rounded-lg border border-slate-100 bg-slate-50 px-4 py-3">
                                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $section['label'] }} findings</p>
                                @if (! empty($section['data']['summary']))
                                    <p class="mt-1 text-sm text-slate-700">{{ $section['data']['summary'] }}</p>
                                @endif
                                @if (! empty($section['data']['query']) && $section['data']['query'] !== null)
                                    <p class="mt-1 text-xs text-slate-400">Search: {{ $section['data']['query'] }}</p>
                                @endif
                                @php($results = is_array($section['data']['results'] ?? null) ? $section['data']['results'] : [])
                                @if ($results !== [])
                                    <ul class="mt-2 space-y-2">
                                        @foreach ($results as $result)
                                            <li class="text-sm">
                                                @if (! empty($result['title']))
                                                    @if (! empty($result['url']))
                                                        <a href="{{ $result['url'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-indigo-600 hover:text-indigo-800">{{ $result['title'] }}</a>
                                                    @else
                                                        <span class="font-medium text-slate-700">{{ $result['title'] }}</span>
                                                    @endif
                                                @elseif (! empty($result['url']))
                                                    <a href="{{ $result['url'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-indigo-600 hover:text-indigo-800">{{ $result['url'] }}</a>
                                                @endif
                                                @if (! empty($result['snippet']))
                                                    <p class="mt-0.5 text-slate-600">{{ $result['snippet'] }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @elseif (empty($section['data']['summary']))
                                    <p class="mt-1 text-sm text-slate-500">No public findings.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-slate-500">No public findings were produced for this inquiry.</p>
                @endif

                @php($audit = is_array($webResearch['audit'] ?? null) ? $webResearch['audit'] : null)
                @if (is_array($audit))
                    <div class="mt-6 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Candidate filter audit</p>
                            @php($auditOutcome = (string) ($audit['filter_outcome'] ?? ''))
                            @if ($auditOutcome !== '')
                                <span class="text-xs capitalize {{ $auditOutcome === 'not_found' || $auditOutcome === 'ambiguous' ? 'text-amber-600' : 'text-slate-500' }}">{{ str_replace('_', ' ', $auditOutcome) }}</span>
                            @endif
                        </div>
                        @php($counts = is_array($audit['counts'] ?? null) ? $audit['counts'] : [])
                        <p class="mt-2 text-sm text-slate-700">
                            {{ $counts['obtained'] ?? 0 }} obtained ·
                            {{ $counts['kept'] ?? 0 }} kept by filter ·
                            {{ $counts['rejected'] ?? 0 }} rejected
                            @if (! empty($audit['rescue_used']))<span class="ml-1 text-xs font-medium text-amber-600">· name-match rescue used</span>@endif
                        </p>
                        @php($rejected = is_array($audit['rejected'] ?? null) ? $audit['rejected'] : [])
                        @if ($rejected !== [])
                            <p class="mt-3 text-xs font-medium uppercase tracking-wide text-slate-400">Rejected candidates ({{ count($rejected) }})</p>
                            <ul class="mt-1 space-y-1">
                                @foreach ($rejected as $rejectedItem)
                                    @php($rejectedTitle = is_array($rejectedItem) ? trim((string) ($rejectedItem['title'] ?? '')) : '')
                                    @php($rejectedUrl = is_array($rejectedItem) ? (string) ($rejectedItem['url'] ?? '') : '')
                                    <li class="text-sm">
                                        @if ($rejectedUrl !== '')
                                            <a href="{{ $rejectedUrl }}" target="_blank" rel="noopener noreferrer" class="text-slate-500 hover:text-slate-700">{{ $rejectedTitle !== '' ? $rejectedTitle : $rejectedUrl }}</a>
                                        @else
                                            <span class="text-slate-500">{{ $rejectedTitle }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    @endif
@endsection