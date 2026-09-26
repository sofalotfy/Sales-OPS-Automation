<?php

namespace App\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Throughput guard for the AI provider (feature 013, US2/US3).
 *
 * Protects the provider's free-tier ceilings across the whole worker fleet by
 * keeping two shared, Redis-backed budgets:
 *
 *  - a fixed-window requests-per-minute cap (`ai-rpm:<YmdHi>`); and
 *  - a concurrent in-flight cap (`ai-inflight`) held from reservation until
 *    `finish()` releases it, which bounds total simultaneous provider HTTP
 *    calls across every inquiry-worker process.
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
 */
class AiThroughputGuard
{
    private const WINDOW_SECONDS = 60;

    private const INFLIGHT_TTL = 300;

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
     * @return string|null a reservation token to pass to finish() when the
     *                     call is actually sent; an empty string when the
     *                     guard is off or unavailable (call allowed,
     *                     uncounted); null when the budget is exhausted
     *                     (call must not be sent).
     */
    public function start(): ?string
    {
        if (! $this->enabled()) {
            return '';
        }

        try {
            $counterKey = 'ai-rpm:'.now()->format('YmdHi');

            $count = $this->connection()->command('incr', [$counterKey]);
            $this->connection()->command('expire', [$counterKey, self::WINDOW_SECONDS]);

            if ($count > $this->maxPerMin()) {
                // Refund the slot we just took: no request will be sent.
                $this->connection()->command('decr', [$counterKey]);

                return null;
            }

            $inflight = $this->connection()->command('incr', ['ai-inflight']);
            $this->connection()->command('expire', ['ai-inflight', self::INFLIGHT_TTL]);

            if ($inflight > $this->maxInflight()) {
                $this->connection()->command('decr', ['ai-inflight']);
                $this->connection()->command('decr', [$counterKey]);

                return null;
            }

            return 'reserved';
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
}
