<?php

namespace App\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Throughput guard for the AI provider (feature 013, US2/US3).
 *
 * Protects the provider's free-tier ceilings across the whole worker fleet by
 * keeping three shared, Redis-backed budgets:
 *
 *  - a fixed-window requests-per-minute cap (`ai-rpm:<YmdHi>`);
 *  - a concurrent in-flight cap (`ai-inflight`) held from reservation until
 *    `finish()` releases it, which bounds total simultaneous provider HTTP
 *    calls across every inquiry-worker process; and
 *  - a token-per-minute cap modelled as the provider's SLIDING 60s window
 *    (`ai-tpm:<bucket>`, summed over the trailing `WINDOW_SECONDS`), which
 *    bookings estimate from input/output characters so a heavy run cannot blow
 *    the provider's token budget (on Groq the binding ceiling is
 *    tokens/minute, not requests/minute — see the 429 bodies).
 *
 * The token window is sliding on purpose. It was originally one wall-clock
 * minute (`ai-tpm:<YmdHi>`), which cannot model a sliding limit: the big calls
 * land on the minute boundary by design, so a full previous minute was
 * invisible and the next wave sailed past the real ceiling into a 429. Buckets
 * are coarse on purpose — summing whole buckets over-counts spend slightly,
 * and over-counting only makes the guard stricter, never more permissive.
 *
 * The counters are shared (Redis) by design, so a fleet of workers respects
 * one budget instead of each maintaining its own (which would multiply the
 * ceiling by the number of processes).
 *
 * Failure semantics: the guard is advisory, not a correctness gate. It fails
 * open — if Redis is unreachable or the config says the guard is off, `start()`
 * returns an empty token meaning "allowed, uncounted" — the pipeline never
 * hangs on the guard. When a budget is exhausted, `start()` returns null and
 * the caller fails that request open (a factor drops, a stage goes
 * indeterminate) exactly as it would if the provider itself were unavailable
 * (FR-007). The reservation is always released either way, so a throttled
 * caller never leaks an in-flight slot.
 *
 * A booked token estimate is charged up-front (so capacity is never promised
 * to two concurrent waves of the same minute window) and returned with
 * `refund()` when the request was rejected before the model consumed any
 * tokens (429/413/5xx/connection errors). Billed responses keep their booking.
 */
class AiThroughputGuard
{
    private const WINDOW_SECONDS = 60;

    /**
     * Width of one token-accounting bucket. The window is the sum of the last
     * `WINDOW_SECONDS / BUCKET_SECONDS` buckets.
     */
    private const BUCKET_SECONDS = 10;

    private const INFLIGHT_TTL = 300;

    private const TOKEN_BUCKET_TTL = 90;

    private ?Connection $redis = null;

    /**
     * Binds an explicit Redis connection. Unit tests fake `command()` through
     * an injected connection; production keeps the app's `default` connection
     * (resolved lazily only when a guarded call is actually made). No
     * constructor parameter here so the container never resolves Redis while
     * building the service graph in environments without a live server.
     */
    public function setConnection(?Connection $redis): void
    {
        $this->redis = $redis;
    }

    /**
     * Try to reserve a provider slot.
     *
     * `$estimatedTokens` (input+output estimate for the request, 0 = unknown)
     * is charged to the current token-per-minute window when provided, so a
     * batch cannot collectively book more than the window holds.
     *
     * @return string|null a reservation token to pass to finish() when the
     *                     call is actually sent; an empty string when the
     *                     guard is off or unavailable (call allowed,
     *                     uncounted); null when the budget is exhausted
     *                     (call must not be sent).
     */
    public function start(int $estimatedTokens = 0): ?string
    {
        if (! $this->enabled()) {
            return '';
        }

        try {
            $counterKey = 'ai-rpm:'.now()->format('YmdHi');
            $bucket = $this->tokenBucket();
            $tokenWeight = max(0, (int) $estimatedTokens);

            // Token room is checked BEFORE spending the rpm/in-flight counters,
            // so a token-full window never churns those budgets while polling.
            if ($tokenWeight > 0 && $this->tokensInWindow($bucket) + $tokenWeight > $this->maxTokensPerMin()) {
                return null;
            }

            $count = $this->connection()->command('incr', [$counterKey]);
            $this->connection()->command('expire', [$counterKey, self::WINDOW_SECONDS]);

            if ($count > $this->maxPerMin()) {
                // Refund the slots we just took: no request will be sent.
                $this->connection()->command('decr', [$counterKey]);

                if ($tokenWeight > 0) {
                    $this->connection()->command('decrby', [$this->tokenKey($bucket), $tokenWeight]);
                }

                return null;
            }

            $inflight = $this->connection()->command('incr', ['ai-inflight']);
            $this->connection()->command('expire', ['ai-inflight', self::INFLIGHT_TTL]);

            if ($inflight > $this->maxInflight()) {
                $this->connection()->command('decr', ['ai-inflight']);
                $this->connection()->command('decr', [$counterKey]);

                if ($tokenWeight > 0) {
                    $this->connection()->command('decrby', [$this->tokenKey($bucket), $tokenWeight]);
                }

                return null;
            }

            if ($tokenWeight > 0) {
                $this->connection()->command('incrby', [$this->tokenKey($bucket), $tokenWeight]);
                $this->connection()->command('expire', [$this->tokenKey($bucket), self::TOKEN_BUCKET_TTL]);
            }

            // The bucket travels with the reservation so a later refund lands on
            // the bucket it was charged to, not on whichever one is current by
            // then (a request rejected seconds later can cross a boundary).
            return 'reserved:'.$bucket;
        } catch (Throwable) {
            // Guard infrastructure down: fail open, never block real work.
            return '';
        }
    }

    public function finish(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }

        try {
            $this->connection()->command('decr', ['ai-inflight']);
        } catch (Throwable) {
            // The safety TTL on ai-inflight eventually clears a missed release.
        }
    }

    /**
     * Return a booked token estimate when the request was never consumed by
     * the model (rate-limit/overload rejection, connection error). Billed
     * responses keep their booking so the window reflects real provider spend.
     *
     * `$reservation` is the token `start()` returned: it names the bucket the
     * booking was charged to, so the refund cannot land in a later bucket (and
     * cannot drive a bucket negative).
     */
    public function refund(int $tokens, ?string $reservation = null): void
    {
        if (! $this->enabled() || $tokens <= 0) {
            return;
        }

        try {
            $bucket = $this->bucketFromReservation($reservation) ?? $this->tokenBucket();
            $this->connection()->command('decrby', [$this->tokenKey($bucket), max(0, (int) $tokens)]);
        } catch (Throwable) {
            // The bucket expires on its own; a missed refund self-corrects.
        }
    }

    /**
     * Non-mutating capacity probe: whether a fresh slot is currently available.
     *
     * Reads the budgets with GET only, so polling callers can wait for a free
     * slot without spending the RPM counter they are waiting on (a start()-per
     * second poll would keep the window pinned at the ceiling forever). When
     * `$estimatedTokens` is given, the token window is included too.
     *
     * @return bool true when under the caps, or the guard is off/unavailable
     */
    public function hasCapacity(int $estimatedTokens = 0): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        try {
            $counterKey = 'ai-rpm:'.now()->format('YmdHi');
            $rpm = (int) ($this->connection()->command('get', [$counterKey]) ?? 0);
            $inflight = (int) ($this->connection()->command('get', ['ai-inflight']) ?? 0);

            $tokenOk = true;

            if ($estimatedTokens > 0) {
                $tokenOk = $this->tokensInWindow($this->tokenBucket()) + $estimatedTokens <= $this->maxTokensPerMin();
            }

            return $rpm < $this->maxPerMin() && $inflight < $this->maxInflight() && $tokenOk;
        } catch (Throwable) {
            return true;
        }
    }

    private function tokenBucket(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? now()->getTimestamp(), self::BUCKET_SECONDS);
    }

    private function tokenKey(int $bucket): string
    {
        return 'ai-tpm:'.$bucket;
    }

    /**
     * Tokens booked across the trailing `WINDOW_SECONDS`, i.e. our model of the
     * provider's sliding per-minute ceiling.
     */
    private function tokensInWindow(int $bucket): int
    {
        $used = 0;

        for ($offset = intdiv(self::WINDOW_SECONDS, self::BUCKET_SECONDS) - 1; $offset >= 0; $offset--) {
            $used += (int) ($this->connection()->command('get', [$this->tokenKey($bucket - $offset)]) ?? 0);
        }

        return $used;
    }

    private function bucketFromReservation(?string $reservation): ?int
    {
        if ($reservation === null || ! str_starts_with($reservation, 'reserved:')) {
            return null;
        }

        $bucket = substr($reservation, strlen('reserved:'));

        return ctype_digit($bucket) ? (int) $bucket : null;
    }

    private function connection(): Connection
    {
        // Resolved lazily (and only once a guarded call is actually being
        // made): a guard that is disabled never touches Redis at all.
        return $this->redis ??= Redis::connection('default');
    }

    private function enabled(): bool
    {
        return (bool) config('services.ai.guard_enabled', false);
    }

    private function maxPerMin(): int
    {
        return max(1, (int) config('services.ai.guard_max_per_min', 30));
    }

    private function maxInflight(): int
    {
        return max(1, (int) config('services.ai.guard_max_inflight', 4));
    }

    private function maxTokensPerMin(): int
    {
        return max(1, (int) config('services.ai.guard_max_tokens_per_min', 7000));
    }
}
