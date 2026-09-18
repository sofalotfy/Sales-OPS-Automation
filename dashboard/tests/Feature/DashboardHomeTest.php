<?php

namespace Tests\Feature;

use App\Support\UpstreamSession;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs;
use Tests\TestCase;

class DashboardHomeTest extends TestCase
{
    public function test_dashboard_home_requires_authentication(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login.show'));
    }

    public function test_dashboard_home_renders_stats_and_recent_documents(): void
    {
        $this->signIn();
        UpstreamStubs::fakeDashboardTotals(
            ready: 3,
            processing: 1,
            failed: 0,
            recentItems: [
                UpstreamStubs::document(id: 'a', title: 'Playbook'),
                UpstreamStubs::document(id: 'b', title: 'Triage notes', status: 'processing'),
            ],
        );

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Playbook')
            ->assertSee('Triage notes')
            ->assertSee('Total documents')
            ->assertSee('Ready')
            ->assertSee('Processing')
            ->assertSee('Failed');
    }

    public function test_dashboard_home_401_forces_relogin(): void
    {
        $this->signIn();
        UpstreamStubs::fakeUpstreamUnauthorized();

        $this->get(route('dashboard'))->assertRedirect(route('login.show'));
    }

    public function test_dashboard_home_surfaces_service_error(): void
    {
        $this->signIn();
        Http::fake([
            UpstreamStubs::ragUrl('/documents*') => Http::response(
                ['detail' => 'service down'],
                503,
            ),
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('service down');
    }

    public function test_login_redirects_to_dashboard_home(): void
    {
        UpstreamStubs::fakeLoginOk();

        $this->post(route('login'), ['username' => 'admin', 'password' => 's3cret-pass'])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(UpstreamSession::authenticated());
    }
}