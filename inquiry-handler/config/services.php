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

    'zai' => [
        'key' => env('ZAI_API_KEY'),
        // Default (weaker, cheap) model for scope checks and any non-research
        // AI call. Kept separate from the research agent's model so the
        // stronger one is only spent where it earns its keep.
        'model' => env('ZAI_MODEL', 'glm-4.5-flash'),
        // Strongest model for the AI research agent's filter + summarize steps.
        'research_model' => env('ZAI_RESEARCH_MODEL', 'glm-4.7-flash'),
        // Per-request HTTP timeout. The free-tier flash models are slow
        // (seconds to ~20s per call), so this needs to be generous.
        'timeout' => (int) env('ZAI_TIMEOUT', 90),
        'url' => env('ZAI_API_URL', 'https://api.z.ai/api/paas/v4/chat/completions'),
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