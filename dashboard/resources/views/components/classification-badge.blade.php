@props(['classification' => null])

@php
    $styles = [
        'high' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'medium' => 'bg-amber-100 text-amber-800 border-amber-200',
        'low' => 'bg-slate-100 text-slate-700 border-slate-200',
        'disqualify' => 'bg-red-100 text-red-800 border-red-200',
    ];
    $classification = $classification === null || $classification === '' ? null : $classification;
    $label = $classification === null
        ? 'Pending'
        : match ($classification) {
            'high' => 'High priority',
            'medium' => 'Medium',
            'low' => 'Low',
            'disqualify' => 'Disqualified',
            default => ucfirst((string) $classification),
        };
    $style = $classification === null
        ? 'bg-slate-50 text-slate-500 border-slate-200'
        : ($styles[$classification] ?? 'bg-slate-100 text-slate-700 border-slate-200');
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium '.$style]) }}>
    {{ $label }}
</span>