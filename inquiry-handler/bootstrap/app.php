<?php

use App\Http\Middleware\BeginInquiryRun;
use App\Http\Middleware\ScopeGateMiddleware;
use App\Http\Middleware\VerifyCrmClient;
use App\Http\Middleware\WebResearchMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

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

        // The sync chain opens the run row first (run.begin), then enriches it
        // with web research before the scope screen. The CRM ingest surface
        // authenticates via the shared X-CRM-Key credential.
        $middleware->alias([
            'run.begin' => BeginInquiryRun::class,
            'scope.gate' => ScopeGateMiddleware::class,
            'web.research' => WebResearchMiddleware::class,
            'crm.key' => VerifyCrmClient::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('inquiry/*') || $request->expectsJson(),
        );

        // Contracted 429 envelope for the CRM ingest surface
        // (contracts/crm-ingest-web.md).
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('inquiry/*')) {
                return response()->json(['detail' => 'Too Many Requests'], 429);
            }
        });
    })->create();
