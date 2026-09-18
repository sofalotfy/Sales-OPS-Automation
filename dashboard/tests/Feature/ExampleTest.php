<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The app root is behind the upstream sign-in; the health probe stays public.
     */
    public function test_health_probe_returns_success(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }
}