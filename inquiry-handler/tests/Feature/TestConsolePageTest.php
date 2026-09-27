<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestConsolePageTest extends TestCase
{
    public function test_health_endpoint_responds_ok(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_test_console_renders_relabeled_heading_and_fields(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('inquiry.index');
        $response->assertSee('Inquiry Handler — Test Console', false);
        $response->assertSee('Your question', false);
        $response->assertSee('First name', false);
        $response->assertSee('Last name', false);
        $response->assertSee('Email', false);
        $response->assertSee('Phone number', false);
        $response->assertSee('Company name', false);
        $response->assertSee('Country/Region', false);
        $response->assertDontSee('id="name"', false);
        $response->assertSee('Send inquiry', false);
        $response->assertSee('inquiry-form', false);
    }

    public function test_test_console_only_enqueues_and_does_not_render_run_results(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="status"', false);
        $response->assertSee('Queued', false);
        $response->assertDontSee('Factor scores', false);
        $response->assertDontSee('Dropped factors', false);
        $response->assertDontSee('System prompt', false);
        $response->assertDontSee('setInterval', false);
    }
}
