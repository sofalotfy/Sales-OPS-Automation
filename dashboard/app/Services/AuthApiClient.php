<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client over auth-service (contract: auth-api.md).
 *
 * Only the sign-in and sign-out endpoints are needed by the dashboard; token
 * introspection is performed implicitly by work-scope-rag on every call the
 * RagApiClient makes with the session's bearer token.
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
     * Exchange credentials for an opaque bearer token (auth-api.md POST /auth/login).
     * Responses are never thrown on; callers inspect status + body.
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

    /**
     * Best-effort revocation of the presented token (auth-api.md POST /auth/logout).
     * May legitimately return 401 (already expired/revoked) — the local session
     * is cleared regardless by the caller.
     */
    public function logout(?string $token): Response
    {
        $request = Http::baseUrl($this->baseUrl())->acceptJson()->timeout(5);
        if ($token !== null) {
            $request = $request->withToken($token);
        }

        return $request->post('/auth/logout');
    }

    public function health(): Response
    {
        return Http::baseUrl($this->baseUrl())->acceptJson()->timeout(5)->get('/health');
    }
}