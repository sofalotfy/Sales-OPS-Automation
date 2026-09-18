<?php

namespace Tests;

use App\Support\UpstreamSession;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No Vite manifest exists in the test environment; render views with
        // the compiled-less fallback (resources/css/app.css / app.js are no-ops).
        $this->withoutVite();
    }

    /** Seed an authenticated session the way AuthController::login does. */
    protected function signIn(string $username = 'admin', string $token = 'stub-token'): void
    {
        UpstreamSession::put($token, $username, now()->addDays(90)->toIso8601String());
    }
}