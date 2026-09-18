<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web-research step tunables
    |--------------------------------------------------------------------------
    |
    | Pre-classification web research: the middleware looks up public
    | information about the inquirer's company and person, attaches the verdict
    | + criteria to the request, and either passes through enriched (accept /
    | fail-open indeterminate) or refuses (decline).
    |
    | `enabled` is the kill switch: when false, the step is fully bypassed and
    | the response carries no web_research marker. `provider` is the class name
    | of your WebResearchProvider implementation (env WEB_RESEARCH_PROVIDER),
    | defaulting to the shipped Tavily-backed TavilyResearchProvider; a missing
    | TAVILY_API_KEY degrades the step to a fail-open indeterminate verdict.
    |
    | The remaining keys bound the AI research agent that filters the gathered
    | candidates to the named company/person, fetches the kept source pages,
    | and summarizes them (feature 010). Every run stays within these budgets;
    | an over-budget run degrades to partial/indeterminate rather than hanging.
    |
    */

    'enabled' => (bool) env('WEB_RESEARCH_ENABLED', true),

    'provider' => env('WEB_RESEARCH_PROVIDER'),

    // Maximum candidate results the agent considers (across the company and
    // person sections) before the AI filter runs.
    'max_candidates' => (int) env('WEB_RESEARCH_MAX_CANDIDATES', 12),

    // Maximum kept sources the agent fetches and cites in the summary.
    'max_sources' => (int) env('WEB_RESEARCH_MAX_SOURCES', 5),

    // Per-page fetch timeout (seconds) and body size cap (bytes).
    'fetch_timeout' => (int) env('WEB_RESEARCH_FETCH_TIMEOUT', 8),
    'fetch_max_bytes' => (int) env('WEB_RESEARCH_FETCH_MAX_BYTES', 200000),

    // Maximum extracted characters kept per fetched source page.
    'source_max_chars' => (int) env('WEB_RESEARCH_SOURCE_MAX_CHARS', 8000),

    // Maximum characters of fetched documents sent to the summarize call.
    'summary_max_input_chars' => (int) env('WEB_RESEARCH_SUMMARY_MAX_INPUT_CHARS', 24000),

    // Overall wall-clock budget for one agent run (seconds). Over-budget runs
    // return what was legitimately gathered (partial) or indeterminate.
    'step_timeout' => (int) env('WEB_RESEARCH_STEP_TIMEOUT', 25),

    // AI filter attempts when the model returns unparseable output. The free
    // tier intermittently emits non-JSON; a bounded retry rides through that
    // instead of failing the whole run (falls back to indeterminate).
    'filter_attempts' => (int) env('WEB_RESEARCH_FILTER_ATTEMPTS', 2),

    // Best-effort rescue: when the AI filter can settle nothing (not_found /
    // ambiguous / empty keep) but candidates still carry the target's name,
    // fetch those and let the grounded summarizer try — marking the result
    // `uncertain` instead of declaring there is no data.
    'rescue_on_name_match' => (bool) env('WEB_RESEARCH_RESCUE_ON_NAME_MATCH', true),

];
