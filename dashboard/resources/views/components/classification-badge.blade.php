@props(['classification' => 'low'])

@php
    $styles = [
        'high' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'medium' => 'bg-amber-100 text-amber-800 border-amber-200',
        'low' => 'bg-slate-100 text-slate-700 border-slate-200',
        'disqualify' => 'bg-red-100 text-red-800 border-red-200',
    ];
    $label = match ($classification) {
        'high' => 'High priority',
        'medium' => 'Medium',
        'low' => 'Low',
        'disqualify' => 'Disqualified',
        default => ucfirst((string) $classification),
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium '.($styles[$classification] ?? 'bg-slate-100 text-slate-700 border-slate-200')]) }}>
    {{ $label }}
</span>