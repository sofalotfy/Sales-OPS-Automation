<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic default booking link for feature tests that exercise
        // the booking disposition (FR-008). Individual tests may override.
        config()->set('services.booking_url', 'https://book.example.com/meet');

        // AiCallingService short-circuits on an empty key BEFORE any HTTP call,
        // so feature tests must supply one (the request itself is faked).
        config()->set('services.ai.key', 'test-ai-key');
        config()->set('services.ai.model', 'test-model');
    }
}