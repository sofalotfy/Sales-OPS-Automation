<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * Server-side holder for the upstream auth token (research §2).
 *
 * The opaque bearer token issued by auth-service is stored ONLY in the
 * Laravel file-based session. It is never rendered to the browser, never
 * placed in a Livewire public property, and never written to logs.
 */
class UpstreamSession
{
    private const KEY_TOKEN = 'upstream_access_token';

    private const KEY_USERNAME = 'upstream_username';

    private const KEY_EXPIRES_AT = 'upstream_expires_at';

    public static function put(string $token, string $username, string $expiresAt): void
    {
        Session::put(self::KEY_TOKEN, $token);
        Session::put(self::KEY_USERNAME, $username);
        Session::put(self::KEY_EXPIRES_AT, $expiresAt);
    }

    public static function token(): ?string
    {
        $value = Session::get(self::KEY_TOKEN);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function username(): ?string
    {
        $value = Session::get(self::KEY_USERNAME);

        return is_string($value) ? $value : null;
    }

    public static function expiresAt(): ?DateTimeImmutable
    {
        $value = Session::get(self::KEY_EXPIRES_AT);
        if (! is_string($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Pre-emptive expiry check so the browser is bounced to sign-in without
     * waiting for an upstream 401 the moment the 90-day token lapses.
     */
    public static function expired(): bool
    {
        $expiresAt = self::expiresAt();

        return $expiresAt !== null && $expiresAt <= new DateTimeImmutable('now');
    }

    /** True when a usable token is present and has not yet expired. */
    public static function authenticated(): bool
    {
        return self::token() !== null && ! self::expired();
    }

    public static function clear(): void
    {
        Session::forget([self::KEY_TOKEN, self::KEY_USERNAME, self::KEY_EXPIRES_AT]);
    }
}