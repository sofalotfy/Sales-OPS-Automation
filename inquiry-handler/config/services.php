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

    // Fixed company/scope statement injected into the system prompt. Hardcoded
    // (never request-influenced); keeps the triage persona aligned with the
    // retrieval corpus.
    'company_scope' => implode("\n", [
        'Robusta Studio is RTG\'s delivery engine for customer experience, commerce, and enterprise digital transformation — its scope spans eight service lines from strategy and product discovery through engineering, e-commerce, AI, data, cloud, and cybersecurity.',
        '',
        'Service lines (Robusta Studio):',
        '- Digital Transformation & Strategy — product discovery & agile advisory, workflow automation, system integration, process digitization',
        '- E-Commerce — end-to-end commerce ecosystems, B2C/B2B/marketplace models, order management & fulfillment, storefront-to-analytics coverage',
        '- Shopify Services — design-first storefront delivery, custom features without a full backend build, post-launch support',
        '- Engineering & Experience — mobile & web apps, UX/UI design systems, prototyping & user testing, accessible interfaces',
        '- Artificial Intelligence — personalization, smart search & NLP, AI assistants & automation, GenAI integration',
        '- Data & Analytics — predictive modeling, data lakes & warehouses, BI dashboards, pipeline architecture',
        '- Cloud & Infrastructure — multi-cloud architecture, CI/CD, Infrastructure-as-Code, cost optimization',
        '- Cybersecurity & Compliance — secure SDLC & code review, penetration testing, virtual CISO, compliance readiness',
        '',
        'Delivery methodology — five stages: Discover → Define → Build → Launch → Grow.',
        'Platforms & partners — Adobe Commerce for enterprise-grade custom commerce, Shopify for rapid-growth storefronts; partners include Adobe, AWS, Microsoft, Paymob, Shopify, Laravel, Hypernode, Stonebranch.',
        'Industries — retail & e-commerce, proptech, govtech, fintech, healthcare, logistics, edtech, telecom.',
        'Scale — 200+ experts, 500+ projects delivered, 250+ clients, 10+ industries. Older third-party profiles still list "100+ consultants" and two hubs (Egypt and Germany), so treat those as outdated.',
        'Group context — Studio is one of RTG\'s four business units, alongside Octopus (tech talent, outsourcing, EOR, digital hubs), Ventures (venture building), and Products (proprietary SaaS/AI: ORDR, NAWRIX, SENTRA). At group level the scope adds two extra lines beyond Studio\'s: e-commerce venture building and tech team building.',
        'Representative engagements — Fit & Fix, Vodafone Ta3limy, Seoudi, Mondelez (Talabya B2B distribution), Mazaya, Spinneys loyalty, Saudi Tourism Authority (Dalila), Al Othaim (Speedi), Raya Shop.',
    ]),

    'ai' => [
        'key' => env('AI_API_KEY'),
        // Default (lighter, fast) model for scope checks and any non-research
        // AI call. Kept separate from the research agent's models so stronger
        // ones are only spent where they earn their keep. Note: on Groq the
        // free tier rejects response_format=json_object for gpt-oss-20b (400
        // json_validate_failed), and the caller only falls back to plain mode
        // on 422 — so the default/filter roles stay on gpt-oss-120b, which
        // serves JSON mode reliably.
        'model' => env('AI_MODEL', 'openai/gpt-oss-120b'),
        // Strongest model for the AI research agent's notes + summarize steps.
        'research_model' => env('AI_RESEARCH_MODEL', 'openai/gpt-oss-120b'),
        // Research candidate filter model: cheap/fast, it only keeps/rejects
        // candidate URLs so it does not need the strongest weights.
        'filter_model' => env('AI_FILTER_MODEL', 'openai/gpt-oss-120b'),
        // Max concurrent in-flight AI requests when a step fans out (layer-1
        // notes batches). Kept at 2 so a single concurrent wave stays under the
        // Groq free tier's 8K-TPM window (each ~8K-char notes call consumes
        // ≈3K input+output tokens): 2 × 3K < 8K, while 4 × 3K blew the budget
        // on every multi-page run. Overshoot self-throttles via 413/429 +
        // retry-after.
        'concurrency' => max(1, (int) env('AI_CONCURRENCY', 2)),
        // Cap on generated tokens per call. gpt-oss defaults to 65K output,
        // which would burn a free tier's daily budget on one summary. Kept
        // well under the 8K-tokens-per-minute Groq window so a single
        // completion cannot starve the rest of a batch.
        'max_output_tokens' => max(1, (int) env('AI_MAX_OUTPUT_TOKENS', 2048)),
        // Per-request HTTP timeout (seconds).
        'timeout' => (int) env('AI_TIMEOUT', 90),
        'url' => env('AI_API_URL', 'https://api.groq.com/openai/v1/chat/completions'),
        // Throughput guard (feature 013 US2/US3): an optional fixed-window
        // requests-per-minute ceiling plus an in-flight cap protecting the
        // provider's free-tier limits. Stats and gates live in Redis so every
        // inquiry-worker shares one budget. Off when AI_GUARD_ENABLED=false.
        // NOTE: the real Groq key ceiling is ≈1000 RPM / 8K TPM — tokens, not
        // requests, bind. RPM defaults high so one research-heavy run (up to
        // ~40 note batches × 2 attempts + filter + summary) never trips a
        // phantom request wall; token pacing is handled by `concurrency`.
        'guard_enabled' => (bool) env('AI_GUARD_ENABLED', true),
        'guard_max_per_min' => max(1, (int) env('AI_MAX_PER_MIN', 150)),
        'guard_max_inflight' => max(1, (int) env('AI_MAX_INFLIGHT', 4)),

        // How long completeMany() will wait (seconds) for a guard slot when the
        // RPM/in-flight budget is momentarily full, instead of failing a batch
        // open. 90s spans one 60s RPM window plus margin.
        'guard_wait_seconds' => max(0, (float) env('AI_GUARD_WAIT_SECONDS', 90)),
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

    // Shared credential the CRM presents as X-CRM-Key (feature 013 US1; key
    // from env only, FR-011). Empty value tight-shuts the CRM surface.
    'crm_key' => env('CRM_API_KEY'),
    // Per-key rate limit (requests/minute) for the CRM ingest surface,
    // enforced via throttle:crm → Redis (contracts/crm-ingest-web.md).
    'crm_rate_limit' => max(1, (int) env('CRM_RATE_MAX_PER_MIN', 120)),

];
