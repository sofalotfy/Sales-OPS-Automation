<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upstream services
    |--------------------------------------------------------------------------
    |
    | This dashboard is a pure HTTP consumer of the project's other services:
    | auth-service (sign-in) and work-scope-rag (document management).
    | URLs come from the environment so Compose DNS and host/local testing
    | can point at the same configuration.
    |
    */

    'auth_api_url' => env('AUTH_API_URL', 'http://auth-service:8001'),
    'rag_api_url' => env('RAG_API_URL', 'http://work-scope-rag:8000'),
    'inquiry_handler_api_url' => env('INQUIRY_HANDLER_URL', 'http://inquiry-handler:8003'),

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
