<?php

namespace Tests\Unit;

use App\Support\AiThroughputGuard;
use Closure;
use Illuminate\Redis\Connections\Connection;
use Tests\TestCase;

/**
 * AiThroughputGuard unit tests (feature 013, US2/US3).
 *
 * The guard keeps three shared Redis budgets — a fixed-window RPM counter, a
 * concurrent in-flight counter, and a sliding-window token-per-minute counter —
 * and fails open when Redis is unavailable. A fake Redis connection records
 * commands and serves scripted return values, so the budgets can be reasoned
 * about without a live server. A disabled guard never touches Redis at all.
 */
class AiThroughputGuardTest extends TestCase
{
    private function guard(FakeRedis $redis): AiThroughputGuard
    {
        $guard = new AiThroughputGuard;
        $guard->setConnection($redis);

        return $guard;
    }

    private function windowKey(): string
    {
        return 'ai-rpm:'.now()->format('YmdHi');
    }

    /**
     * The token window is the sum of the trailing 60s in 10s buckets, so a test
     * can address the current bucket or any bucket behind it.
     */
    private function tokenKey(int $bucketsAgo = 0): string
    {
        return 'ai-tpm:'.intdiv(now()->getTimestamp() - ($bucketsAgo * 10), 10);
    }

    public function test_disabled_guard_allows_without_touching_redis(): void
    {
        config(['services.ai.guard_enabled' => false]);
        $redis = new FakeRedis;

        $guard = $this->guard($redis);

        $this->assertSame('', $guard->start());
        $this->assertSame([], $redis->calls);
    }

    public function test_within_budget_reserves_and_counts_rpm(): void
    {
        config(['services.ai.guard_enabled' => true]);
        config(['services.ai.guard_max_per_min' => 2]);
        config(['services.ai.guard_max_inflight' => 4]);
        $redis = new FakeRedis([
            'incr:'.$this->windowKey() => [1, 2],
        ]);

        $guard = $this->guard($redis);

        $this->assertStringStartsWith('reserved:', (string) $guard->start());
        $this->assertStringStartsWith('reserved:', (string) $guard->start());

        $this->assertSame(2, $this->countMethod($redis, 'incr', $this->windowKey()));
        $this->assertSame(2, $this->countMethod($redis, 'expire', $this->windowKey()));
        $this->assertSame(2, $this->countMethod($redis, 'incr', 'ai-inflight'));
    }

    public function test_rpm_budget_exhausted_refunds_the_slot(): void
    {
        config(['services.ai.guard_enabled' => true]);
        config(['services.ai.guard_max_per_min' => 2]);
        config(['services.ai.guard_max_inflight' => 4]);
        $redis = new FakeRedis([
            'incr:'.$this->windowKey() => [1, 2, 3],
        ]);

        $guard = $this->guard($redis);

        $this->assertStringStartsWith('reserved:', (string) $guard->start());
        $this->assertStringStartsWith('reserved:', (string) $guard->start());
        $this->assertNull($guard->start());

        // The third attempt's counter slot is refunded so the budget is not
        // depressed for a call that never runs.
        $this->assertSame(1, $this->countMethod($redis, 'decr', $this->windowKey()));
        $this->assertSame(2, $this->countMethod($redis, 'incr', 'ai-inflight'));
    }

    public function test_inflight_budget_exhausted_refunds_both_slots(): void
    {
        config(['services.ai.guard_enabled' => true]);
        config(['services.ai.guard_max_per_min' => 30]);
        config(['services.ai.guard_max_inflight' => 1]);
        $redis = new FakeRedis([
            'incr:ai-inflight' => [1, 2],
        ]);

        $guard = $this->guard($redis);

        $this->assertStringStartsWith('reserved:', (string) $guard->start());
        $this->assertNull($guard->start());

        $this->assertSame(1, $this->countMethod($redis, 'decr', 'ai-inflight'));
        $this->assertSame(1, $this->countMethod($redis, 'decr', $this->windowKey()));
    }

    public function test_has_capacity_reads_budgets_without_spending(): void
    {
        config(['services.ai.guard_enabled' => true]);
        config(['services.ai.guard_max_per_min' => 10]);
        config(['services.ai.guard_max_inflight' => 4]);
        $redis = new FakeRedis([
            'get:'.$this->windowKey() => [3],
            'get:ai-inflight' => [1],
        ]);

        $guard = $this->guard($redis);

        $this->assertTrue($guard->hasCapacity());

        // The probe must not mutate either budget: GET only, no incr/expire.
        $this->assertSame(0, $this->countMethod($redis, 'incr', $this->windowKey()));
        $this->assertSame(0, $this->countMethod($redis, 'incr', 'ai-inflight'));
        $this->assertSame(2, $this->countMethod($redis, 'get', $this->windowKey()) + $this->countMethod($redis, 'get', 'ai-inflight'));
    }

    public function test_has_capacity_returns_false_when_a_budget_is_at_the_cap(): void
    {
        config(['services.ai.guard_enabled' => true]);
        config(['services.ai.guard_max_per_min' => 10]);
        config(['services.ai.guard_max_inflight' => 4]);
        $redis = new FakeRedis([
            'get:'.$this->windowKey() => [10],
            'get:ai-inflight' => [0],
        ]);

        $guard = $this->guard($redis);

        $this->assertFalse($guard->hasCapacity());
    }

    public function test_start_books_estimated_tokens_into_the_tpm_window(): void
    {
        config(['services.ai.guard_enabled' => true]);
        config(['services.ai.guard_max_per_min' => 30]);
        config(['services.ai.guard_max_inflight' => 4]);
        config(['services.ai.guard_max_tokens_per_min' => 7000]);
        $redis = new FakeRedis;

        $guard = $this->guard($redis);

        $this->assertStringStartsWith('reserved:', (string) $guard->start(2500));

        $this->assertSame(1, $this->countMethod($redis, 'incrby', $this->tokenKey()));
        $this->assertSame(1, $this->countMethod($redis, 'expire', $this->tokenKey()));
    }

    public function test_start_returns_null_when_the_token_window_is_full_without_spending_rpm(): void
    {
        config(['services.ai.guard_enabled' => true]);
        config(['services.ai.guard_max_per_min' => 150]);
        config(['services.ai.guard_max_inflight' => 4]);
        config(['services.ai.guard_max_tokens_per_min' => 7000]);
        $redis = new FakeRedis([
            'get:'.$this->tokenKey() => [6500],
        ]);

        $guard = $this->guard($redis);

        // 6500 + 1000 > 7000 → over the token budget: no slot, and the rpm /
        // in-flight budgets stay untouched so polling a full window does not
        // churn them.
        $this->assertNull($guard->start(1000));
        $this->assertSame(0, $this->countMethod($redis, 'incr', $this->windowKey()));
        $this->assertSame(0, $this->countMethod($redis, 'incr', 'ai-inflight'));
    }

    public function test_token_window_slides_so_previous_buckets_still_count(): void
    {
        // Regression: the window was one wall-clock minute, so a wave that
        // finished at :59 was invisible to a call at :00 and the pair together
        // sailed past the provider's real (sliding) ceiling into a 429. Usage
        // in the bucket right behind the current one must still be charged.
        config(['services.ai.guard_enabled' => true]);
        config(['services.ai.guard_max_per_min' => 150]);
        config(['services.ai.guard_max_inflight' => 4]);
        config(['services.ai.guard_max_tokens_per_min' => 7000]);
        $redis = new FakeRedis([
            'get:'.$this->tokenKey(2) => [6500, 6500],
        ]);

        $guard = $this->guard($redis);

        $this->assertNull($guard->start(1000));
        $this->assertFalse($guard->hasCapacity(1000));
    }

    public function test_token_window_forgets_buckets_older_than_the_sliding_minute(): void
    {
        // The mirror image: spend from 70s ago is outside the window and must
        // not throttle the current call.
        config(['services.ai.guard_enabled' => true]);
        config(['services.ai.guard_max_per_min' => 150]);
        config(['services.ai.guard_max_inflight' => 4]);
        config(['services.ai.guard_max_tokens_per_min' => 7000]);
        $redis = new FakeRedis([
            'get:'.$this->tokenKey(8) => [6500],
        ]);

        $guard = $this->guard($redis);

        $this->assertIsString($guard->start(1000));
    }

    public function test_refund_returns_tokens_to_the_tpm_window(): void
    {
        config(['services.ai.guard_enabled' => true]);
        $redis = new FakeRedis;

        $guard = $this->guard($redis);
        $reservation = $guard->start(1800);
        $this->assertIsString($reservation);

        $guard->refund(1800, $reservation);

        $this->assertSame(1, $this->countMethod($redis, 'decrby', $this->tokenKey()));

        // Non-positive refunds are no-ops.
        $guard->refund(0);
        $guard->refund(-5);
        $this->assertSame(1, $this->countMethod($redis, 'decrby', $this->tokenKey()));
    }

    public function test_refund_targets_the_bucket_the_booking_charged(): void
    {
        // A request rejected seconds later can cross a bucket boundary; the
        // refund has to land on the bucket it was charged to, not the current
        // one (which would drive a fresh bucket negative and under-count).
        config(['services.ai.guard_enabled' => true]);
        $redis = new FakeRedis;

        $guard = $this->guard($redis);

        $guard->refund(1800, 'reserved:'.(intdiv(now()->getTimestamp(), 10) - 3));

        $this->assertSame(1, $this->countMethod($redis, 'decrby', $this->tokenKey(3)));
        $this->assertSame(0, $this->countMethod($redis, 'decrby', $this->tokenKey()));
    }

    public function test_disabled_guard_ignores_token_bookings(): void
    {
        config(['services.ai.guard_enabled' => false]);
        $redis = new FakeRedis;

        $guard = $this->guard($redis);

        $this->assertSame('', $guard->start(4000));
        $guard->refund(4000);
        $this->assertSame([], $redis->calls);
    }

    public function test_has_capacity_accounts_for_the_token_window_when_estimated(): void
    {
        config(['services.ai.guard_enabled' => true]);
        config(['services.ai.guard_max_per_min' => 150]);
        config(['services.ai.guard_max_inflight' => 4]);
        config(['services.ai.guard_max_tokens_per_min' => 7000]);
        $redis = new FakeRedis([
            'get:'.$this->windowKey() => [10],
            'get:ai-inflight' => [1],
            'get:'.$this->tokenKey() => [6500],
        ]);

        $guard = $this->guard($redis);

        // 6500 + 1000 > 7000 → over the token budget.
        $this->assertFalse($guard->hasCapacity(1000));
        // The request budgets have room and a smaller request fits.
        $this->assertTrue($guard->hasCapacity(500));
    }

    public function test_finish_releases_the_inflight_slot(): void
    {
        config(['services.ai.guard_enabled' => true]);
        $redis = new FakeRedis;

        $guard = $this->guard($redis);

        $token = $guard->start();
        $guard->finish($token);

        $this->assertSame(1, $this->countMethod($redis, 'decr', 'ai-inflight'));

        // Uncancelled / uncounted tokens (empty, null) release nothing.
        $guard->finish('');
        $guard->finish(null);
        $this->assertSame(1, $this->countMethod($redis, 'decr', 'ai-inflight'));
    }

    public function test_redis_failure_fails_open(): void
    {
        config(['services.ai.guard_enabled' => true]);
        $redis = new FakeRedis([], throwOn: true);

        $guard = $this->guard($redis);

        $this->assertSame('', $guard->start());
        $guard->finish('reserved'); // never throws either
        $this->assertSame(0, count($redis->calls));
    }

    private function countMethod(FakeRedis $redis, string $method, string $key): int
    {
        return count(array_filter(
            $redis->calls,
            fn (array $call) => $call[0] === $method && ($call[1][0] ?? null) === $key,
        ));
    }
}

/**
 * In-memory Redis connection that records commands and returns scripted values
 * (per `method:key`) or defaults (incr → 1, expire/decr → 0/true).
 */
class FakeRedis extends Connection
{
    /** @var array<int, array{0: string, 1: array}> */
    public array $calls = [];

    /** @var array<string, array<int, int|bool>> */
    private array $values;

    private bool $throwOn;

    /**
     * @param  array<string, array<int, int|bool>>  $values  command values per `method:key`
     */
    public function __construct(array $values = [], bool $throwOn = false)
    {
        $this->values = $values;
        $this->throwOn = $throwOn;
    }

    public function createSubscription($channels, Closure $callback, $method = 'subscribe') {}

    public function command($method, array $parameters = [])
    {
        if ($this->throwOn) {
            throw new \RuntimeException('redis down');
        }

        $this->calls[] = [$method, $parameters];
        $key = strtolower((string) $method).':'.(string) ($parameters[0] ?? '');

        if (isset($this->values[$key]) && $this->values[$key] !== []) {
            return array_shift($this->values[$key]);
        }

        return match ($method) {
            'incr' => 1,
            'expire' => 1,
            default => 0,
        };
    }
}
