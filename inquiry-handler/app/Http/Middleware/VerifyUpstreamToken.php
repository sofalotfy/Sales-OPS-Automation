<?php

namespace App\Http\Middleware;

use App\Services\AuthApiClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the admin factor-settings API on a valid auth-service session token
 * (research R6 / contracts/factor-settings-admin.md).
 *
 * The caller presents `Authorization: Bearer <token>`; this middleware forwards
 * that token to auth-service GET /auth/verify. A 2xx (200/201) from auth-service
 * lets the request through; anything else (missing/invalid/revoked token →
 * non-2xx, unreachable auth-service → connection-friendly non-2xx handling)
 * yields a 401. Mirrors the stack's established bearer-verification pattern
 * (work-scope-rag require_valid_token).
 */
class VerifyUpstreamToken
{
    public function __construct(private readonly AuthApiClient $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $this->unauthorized();
        }

        try {
            $verification = $this->auth->verify($token);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return $this->unauthorized();
        }

        if (! $verification->successful()) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['detail' => 'Not authenticated.'], 401);
    }
}