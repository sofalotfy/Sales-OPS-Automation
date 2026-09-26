<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the CRM ingest surface (feature 013, US1): the CRM presents a
 * shared credential via `X-CRM-Key`; the value must match `services.crm_key`
 * (constant-time comparison, so a wrong key does not leak timing
 * differences). Missing or mismatched credentials → 401 with the same
 * `{"detail": ...}` envelope the stack's other auth middleware returns
 * (contract: contracts/crm-ingest-web.md).
 */
class VerifyCrmClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.crm_key');
        $candidate = (string) $request->header('X-CRM-Key');

        if ($expected === '' || $candidate === '') {
            return $this->unauthorized();
        }

        if (! hash_equals($expected, $candidate)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['detail' => 'Invalid or missing CRM API key.'], 401);
    }
}
