<?php

namespace Tests\Feature;

use App\Services\AuthApiClient;
use App\Support\UpstreamSession;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use WithFaker;

    public function test_unauthenticated_visitor_is_redirected_to_sign_in(): void
    {
        $this->get(route('documents.index'))
            ->assertRedirect(route('login.show'));
    }

    public function test_health_endpoint_is_public(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_valid_credentials_store_token_and_reach_dashboard(): void
    {
        UpstreamStubs::fakeLoginOk();

        $this->post(route('login'), ['username' => 'admin', 'password' => 's3cret-pass'])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(UpstreamSession::authenticated());
        $this->assertSame('stub-token', UpstreamSession::token());
        $this->assertSame('admin', UpstreamSession::username());
    }

    public function test_wrong_credentials_show_generic_error_and_no_session(): void
    {
        UpstreamStubs::fakeLoginRejected();

        $this->post(route('login'), ['username' => 'admin', 'password' => 'wrong'])
            ->assertRedirect()
            ->assertSessionHasErrors('username')
            ->assertSessionDoesntHaveErrors('password');

        $this->assertFalse(UpstreamSession::authenticated());
    }

    public function test_invalid_submission_is_rejected_before_upstream_call(): void
    {
        Http::fake();

        $this->post(route('login'), ['username' => '', 'password' => ''])
            ->assertSessionHasErrors(['username', 'password']);

        Http::assertNothingSent();
    }

    public function test_sign_out_clears_session_and_revokes_upstream_token(): void
    {
        UpstreamSession::put('stub-token', 'admin', now()->addDays(90)->toIso8601String());
        UpstreamStubs::fakeLogoutOk();

        $this->post(route('logout'))
            ->assertRedirect(route('login.show'));

        $this->assertFalse(UpstreamSession::authenticated());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/auth/logout'));
    }

    public function test_signed_in_visitor_is_sent_from_login_to_dashboard(): void
    {
        UpstreamSession::put('stub-token', 'admin', now()->addDays(90)->toIso8601String());

        $this->get(route('login.show'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_upstream_unavailable_login_fails_gracefully(): void
    {
        Http::fake([
            UpstreamStubs::authUrl('/auth/login') => Http::response(
                ['detail' => 'service down'],
                503,
            ),
        ]);

        $this->post(route('login'), ['username' => 'admin', 'password' => 's3cret-pass'])
            ->assertRedirect()
            ->assertSessionHasErrors();
    }
}