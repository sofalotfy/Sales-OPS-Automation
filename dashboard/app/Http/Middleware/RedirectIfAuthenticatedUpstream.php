<?php

namespace App\Http\Middleware;

use App\Support\UpstreamSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Companion of AuthenticateWithUpstream: an already-signed-in visitor to the
 * sign-in screen is sent straight to the dashboard home instead of re-signing in.
 */
class RedirectIfAuthenticatedUpstream
{
    public function handle(Request $request, Closure $next): Response
    {
        if (UpstreamSession::authenticated()) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}