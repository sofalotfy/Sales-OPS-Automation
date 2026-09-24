<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upstream services
    |--------------------------------------------------------------------------
    |
    | This handler is a pure HTTP consumer of the project's other services:
    | auth-service (service-account login for the RAG bearer token) and
    | work-scope-rag (POST /query retrieval), plus the AI provider (Z.AI).
    | URLs/api keys come from the environment so Compose DNS and host/local
    | testing can point at the same configuration (FR-011: key from env only).
    |
    */

    'auth_api_url' => env('AUTH_API_URL', 'http://auth-service:8001'),
    'rag_api_url' => env('RAG_API_URL', 'http://work-scope-rag:8000'),

    // Deployment-specific company/scope statement injected into the system
    // prompt. A fixed string per deployment (env), never request-influenced;
    // keeps the triage persona aligned with the retrieval corpus.
    'company_scope' => env(
        'COMPANY_SCOPE',
        'a B2B software-automation consultancy that builds enterprise web applications, '
            .'automation workflows, and sales/CRM tooling for B2B software companies',
    ),

    'ai' => [
        'key' => env('AI_API_KEY'),
        // Default (lighter, fast) model for scope checks and any non-research
        // AI call. Kept separate from the research agent's models so stronger
        // ones are only spent where they earn their keep.
        'model' => env('AI_MODEL', 'openai/gpt-oss-20b'),
        // Strongest model for the AI research agent's notes + summarize steps.
        'research_model' => env('AI_RESEARCH_MODEL', 'openai/gpt-oss-120b'),
        // Research candidate filter model: cheap/fast, it only keeps/rejects
        // candidate URLs so it does not need the strongest weights.
        'filter_model' => env('AI_FILTER_MODEL', 'openai/gpt-oss-20b'),
        // Max concurrent in-flight AI requests when a step fans out (layer-1
        // notes batches). Bounded by Groq free-tier limits (30 RPM, 8K TPM);
        // bursts above the token ceiling self-throttle via 429 + retry-after.
        'concurrency' => max(1, (int) env('AI_CONCURRENCY', 4)),
        // Cap on generated tokens per call. gpt-oss defaults to 65K output,
        // which would burn a free tier's daily budget on one summary.
        'max_output_tokens' => max(1, (int) env('AI_MAX_OUTPUT_TOKENS', 4096)),
        // Per-request HTTP timeout (seconds).
        'timeout' => (int) env('AI_TIMEOUT', 90),
        'url' => env('AI_API_URL', 'https://api.groq.com/openai/v1/chat/completions'),
    ],

    'service_account' => [
        'username' => env('SERVICE_USERNAME'),
        'password' => env('SERVICE_PASSWORD'),
    ],

    // Web-research step (company/person lookup): Tavily search API. Key from
    // env only (FR-011); a missing key fails the step open (indeterminate).
    'tavily' => [
        'key' => env('TAVILY_API_KEY'),
        'url' => env('TAVILY_API_URL', 'https://api.tavily.com/search'),
        'search_depth' => env('TAVILY_SEARCH_DEPTH', 'basic'),
        'max_results' => env('TAVILY_MAX_RESULTS', 5),
    ],

    'booking_url' => env('BOOKING_URL'),

];