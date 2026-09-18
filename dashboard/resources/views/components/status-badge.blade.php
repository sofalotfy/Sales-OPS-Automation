@props(['status' => 'unknown'])

@php
    $styles = [
        'processing' => 'bg-amber-100 text-amber-800 border-amber-200',
        'ready' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'failed' => 'bg-red-100 text-red-800 border-red-200',
    ];
    $label = match ($status) {
        'processing' => 'Processing',
        'ready' => 'Ready',
        'failed' => 'Failed',
        default => ucfirst((string) $status),
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium '.($styles[$status] ?? 'bg-slate-100 text-slate-700 border-slate-200')]) }}>
    @if ($status === 'processing')
        <span class="mr-1 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500"></span>
    @endif
    {{ $label }}
</span>