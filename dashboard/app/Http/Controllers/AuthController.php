<?php

namespace App\Http\Controllers;

use App\Services\AuthApiClient;
use App\Support\UpstreamSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthApiClient $auth)
    {
    }

    public function showLogin()
    {
        return view('auth.login');
    }

    /**
     * Sign-in: verifies credentials against auth-service and holds the issued
     * opaque token in the server-side session (research §2). Generic errors
     * never reveal whether the username or password was wrong (FR-002).
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $response = $this->auth->login($credentials['username'], $credentials['password']);

        if ($response->clientError() || $response->serverError()) {
            $message = $response->status() === 401 || $response->status() === 429
                ? 'Invalid credentials. Please try again.'
                : 'The authentication service is unavailable. Please try again later.';

            return back()
                ->withErrors(['username' => $message])
                ->onlyInput('username');
        }

        $body = $response->json();

        UpstreamSession::put(
            $body['access_token'] ?? '',
            $credentials['username'],
            $body['expires_at'] ?? now()->addDays(90)->toIso8601String(),
        );

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /**
     * Sign-out: clear the local session unconditionally, then best-effort
     * revoke the upstream token (FR-003 / auth-api.md POST /auth/logout).
     */
    public function logout(Request $request): RedirectResponse
    {
        $token = UpstreamSession::token();
        UpstreamSession::clear();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($token !== null) {
            try {
                $this->auth->logout($token);
            } catch (\Throwable) {
                // Best effort only — local access is already ended.
            }
        }

        return redirect()->route('login.show');
    }
}