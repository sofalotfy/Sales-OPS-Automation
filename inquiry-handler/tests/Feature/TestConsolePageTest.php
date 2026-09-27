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
        $response->assertSee('What can we help you with?', false);
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

    public function test_test_console_marks_company_required_and_email_optional(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="email" name="email" type="email" maxlength="255" placeholder="jane@example.com"', false);
        $response->assertDontSee('id="email" name="email" type="email" maxlength="255" required', false);
        $response->assertSee('id="company-name" name="company_name" type="text" maxlength="255" required', false);
        $response->assertSee('<label for="email">Email <small>(optional)</small></label>', false);
        $response->assertSee('<label for="company-name">Company name</label>', false);
    }

    public function test_test_console_offers_sample_requests(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="examples"', false);
        $response->assertSee('Or start from a sample request:', false);
        $response->assertSee('B2B commerce build', false);
        $response->assertSee('Out of scope', false);
    }

    public function test_test_console_polls_the_run_and_renders_the_result(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="status"', false);
        $response->assertSee('id="run-panel"', false);
        $response->assertSee('id="run-details"', false);
        $response->assertSee('Factor scores', false);
        $response->assertSee('Dropped factors', false);
        $response->assertSee('Reasoning', false);
        // The run is followed to a verdict rather than left on the 202.
        $response->assertSee('/inquiry/${inquiryId}', false);
        $response->assertSee('succeeded', false);
        $response->assertSee('failed', false);
        $response->assertSee('Send another inquiry', false);
    }
}
