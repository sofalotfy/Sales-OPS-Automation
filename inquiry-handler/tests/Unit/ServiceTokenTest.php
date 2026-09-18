<?php

namespace Tests\Unit;

use App\Services\AuthApiClient;
use App\Support\ServiceToken;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs as Stubs;
use Tests\TestCase;

class ServiceTokenTest extends TestCase
{
    private ServiceToken $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.service_account.username', 'svc-handler');
        config()->set('services.service_account.password', 'secret');
        $this->tokens = new ServiceToken(new AuthApiClient());
    }

    public function test_logs_in_on_first_token_access(): void
    {
        Stubs::fakeLoginOk('first-token');

        $this->assertSame('first-token', $this->tokens->token());
        Http::assertSentCount(1);
    }

    public function test_reuses_cached_token_across_accesses(): void
    {
        Stubs::fakeLoginOk('cached-token');

        $this->assertSame('cached-token', $this->tokens->token());
        $this->assertSame('cached-token', $this->tokens->token());

        Http::assertSentCount(1);
    }

    public function test_refreshes_after_invalidation(): void
    {
        Http::fake([
            Stubs::authUrl('/auth/login') => Http::sequence()
                ->push(Stubs::loginResponse('token-a'))
                ->push(Stubs::loginResponse('token-b')),
        ]);

        $this->assertSame('token-a', $this->tokens->token());
        $this->tokens->invalidate();
        $this->assertSame('token-b', $this->tokens->token());
    }

    public function test_rejects_failed_login_with_null_token(): void
    {
        Stubs::fakeLoginRejected();

        $this->assertNull($this->tokens->token());
    }

    public function test_requires_service_account_credentials_configured(): void
    {
        config()->set('services.service_account.username', null);
        config()->set('services.service_account.password', null);
        Stubs::fakeLoginRejected();

        $this->assertNull($this->tokens->token());
    }
}