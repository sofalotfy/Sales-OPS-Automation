<?php

use App\Http\Middleware\VerifyCrmClient;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The public JSON endpoints (test console triage, CRM ingest/poll)
        // carry no browser session/cookie, so CSRF does not apply to them. The
        // admin factor-settings endpoints are likewise CSRF-exempt because
        // they authenticate via bearer token (VerifyUpstreamToken), not the
        // session cookie. Payloads must arrive unmutated (the default
        // TrimStrings / ConvertEmptyStringsToNull would otherwise turn a
        // whitespace-only message into null before the validator can give a
        // precise error). The static test console page stays fully
        // CSRF-protected.
        $middleware->validateCsrfTokens(except: ['inquiry/*', 'admin/*']);
        $middleware->trimStrings([
            fn ($request) => $request->is('inquiry/*'),
        ]);
        $middleware->convertEmptyStringsToNull([
            fn ($request) => $request->is('inquiry/*'),
        ]);

        // The pipeline runs in the Redis worker now, so there is no per-request
        // stage middleware left to alias: ProcessTriageJob calls the research,
        // scope and scoring stages directly. The inquiry surface authenticates
        // via the shared X-CRM-Key credential.
        $middleware->alias([
            'crm.key' => VerifyCrmClient::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('inquiry/*') || $request->expectsJson(),
        );
    })->create();
