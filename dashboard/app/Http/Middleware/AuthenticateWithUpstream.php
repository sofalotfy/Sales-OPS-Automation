<?php

namespace App\Http\Middleware;

use App\Support\UpstreamSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every management route on an active upstream session (spec FR-001).
 *
 * An authenticated session means a bearer token is held server-side and has
 * not expired locally. A missing or expired session redirects to sign-in;
 * actual rejection of a stale/revoked/disabled token happens upstream on the
 * RAG service and is handled by the callers' 401 handling (clears session +
 * redirect via the same guard check on the next visited route).
 */
class AuthenticateWithUpstream
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! UpstreamSession::authenticated()) {
            return redirect()->route('login.show');
        }

        return $next($request);
    }
}