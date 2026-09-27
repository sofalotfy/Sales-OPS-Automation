{{--
    Inclusive calendar-day range for the classification reporting window.

    Shared by the dashboard home page and the classification log so both offer
    the same control and read the same `from`/`to` parameters. The bounds are
    `Y-m-d` in the app timezone (UTC), which is what the handler filters on, so
    the day picked here is the day it selects.

    Auto-submits on change (see resources/js/app.js), so the form carries no
    apply button: the cards and rows move as the days are chosen. `data-*`
    attributes rather than ids, because the classification page and the home
    page each render their own copy.
--}}
@props(['range'])

<div>
    <label for="filter-from" class="mb-1 block text-xs font-medium text-slate-500">From</label>
    <input
        type="date"
        id="filter-from"
        name="from"
        value="{{ $range->from }}"
        @if ($range->to) max="{{ $range->to }}" @endif
        class="block rounded-md border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
    >
</div>

<div>
    <label for="filter-to" class="mb-1 block text-xs font-medium text-slate-500">To</label>
    <input
        type="date"
        id="filter-to"
        name="to"
        value="{{ $range->to }}"
        @if ($range->from) min="{{ $range->from }}" @endif
        class="block rounded-md border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
    >
</div>
