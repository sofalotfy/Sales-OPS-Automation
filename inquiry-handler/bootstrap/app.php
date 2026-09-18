<?php

use App\Http\Middleware\ScopeGateMiddleware;
use App\Http\Middleware\WebResearchMiddleware;
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
        // The triage endpoint is a public JSON API consumed by the test
        // console page via fetch(); it carries no browser session/cookie, so
        // CSRF does not apply to it. The admin factor-settings endpoints are
        // likewise CSRF-exempt because they authenticate via bearer token
        // (VerifyUpstreamToken), not the session cookie. Payloads must arrive
        // unmutated (the default TrimStrings / ConvertEmptyStringsToNull would
        // otherwise turn a whitespace-only message into null before the
        // validator can give a precise error). The static test console page
        // stays fully CSRF-protected.
        $middleware->validateCsrfTokens(except: ['inquiry/triage', 'admin/*']);
        $middleware->trimStrings([
            fn ($request) => $request->is('inquiry/*'),
        ]);
        $middleware->convertEmptyStringsToNull([
            fn ($request) => $request->is('inquiry/*'),
        ]);

        // Pre-classification scope gate: applied to the public triage path
        // only (feature 007); admin/operator endpoints are a different
        // audience and are never screened (spec Assumption). The web-research
        // step runs BEFORE the scope gate: it enriches the request with public
        // company/person findings (or can decline) before the scope screen.
        $middleware->alias([
            'scope.gate' => ScopeGateMiddleware::class,
            'web.research' => WebResearchMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('inquiry/*') || $request->expectsJson(),
        );
    })->create();
