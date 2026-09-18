<?php

namespace App\Support;

use App\Services\AuthApiClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * In-memory cached bearer token for the service account (research §2).
 *
 * The token is held ONLY in server memory — never rendered, never logged,
 * never written anywhere. It is refreshed lazily: on first use, on expiry,
 * or explicitly after a RAG 401 (RagApiClient calls invalidate() + retries).
 */
class ServiceToken
{
    private ?string $token = null;

    private ?CarbonInterface $expiresAt = null;

    public function __construct(private readonly ?AuthApiClient $auth = null)
    {
    }

    /**
     * Current usable token (authenticating the service account if needed).
     * Returns null when login fails — never throws, callers escalate instead.
     */
    public function token(): ?string
    {
        if ($this->token !== null && $this->expiresAt?->isFuture()) {
            return $this->token;
        }

        return $this->refresh() ? $this->token : null;
    }

    /**
     * Force a fresh login. True on success, false on any failure (403/401/5xx
     * or an unparseable payload) with the cached token cleared.
     */
    public function refresh(): bool
    {
        $this->token = null;
        $this->expiresAt = null;

        try {
            $response = $this->auth()->login(
                (string) config('services.service_account.username'),
                (string) config('services.service_account.password'),
            );
        } catch (Throwable) {
            return false;
        }

        if ($response->failed()) {
            return false;
        }

        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            return false;
        }

        $this->token = $accessToken;

        $expiresAt = $response->json('expires_at');
        if (is_string($expiresAt)) {
            try {
                $this->expiresAt = Carbon::parse($expiresAt);
            } catch (Throwable) {
                $this->expiresAt = Carbon::now()->addMinutes(5);
            }
        } else {
            $this->expiresAt = Carbon::now()->addMinutes(5);
        }

        return true;
    }

    /** Drop the cached token so the next access authenticates again. */
    public function invalidate(): void
    {
        $this->token = null;
        $this->expiresAt = null;
    }

    private function auth(): AuthApiClient
    {
        return $this->auth ?? app(AuthApiClient::class);
    }
}