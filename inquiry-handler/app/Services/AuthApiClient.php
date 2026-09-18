<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client over auth-service (contract: auth-api.md).
 *
 * The handler has no human session; it logs in a dedicated service account to
 * obtain the opaque bearer token later attached to RAG calls (research §2).
 * Responses are never thrown on; the ServiceToken cache inspects status + body.
 */
class AuthApiClient
{
    public function __construct(private readonly ?string $baseUrl = null)
    {
    }

    private function baseUrl(): string
    {
        return $this->baseUrl ?: (string) config('services.auth_api_url');
    }

    /**
     * Exchange service-account credentials for an opaque bearer token
     * (auth-api.md POST /auth/login → {access_token, token_type, expires_at}).
     */
    public function login(string $username, string $password): Response
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout(5)
            ->post('/auth/login', [
                'username' => $username,
                'password' => $password,
            ]);
    }

    public function health(): Response
    {
        return Http::baseUrl($this->baseUrl())->acceptJson()->timeout(5)->get('/health');
    }

    /**
     * Validate an upstream caller's bearer token via auth-service GET /auth/verify.
     * Responses are never thrown on; VerifyUpstreamToken inspects the status
     * (research R6 / contracts/factor-settings-admin.md).
     */
    public function verify(string $token): Response
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($token)
            ->timeout(5)
            ->get('/auth/verify');
    }
}