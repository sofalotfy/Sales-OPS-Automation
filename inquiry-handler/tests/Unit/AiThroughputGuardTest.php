<?php

namespace Tests\Unit;

use App\Support\AiThroughputGuard;
use Closure;
use Illuminate\Redis\Connections\Connection;
use Tests\TestCase;

/**
 * AiThroughputGuard unit tests (feature 013, US2/US3).
 *
 * The guard keeps two shared Redis budgets — a fixed-window RPM counter and a
 * concurrent in-flight counter — and fails open when Redis is unavailable. A
 * fake Redis connection records commands and serves scripted return values, so
 * the budgets can be reasoned about without a live server. A disabled guard
 * never touches Redis at all.
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

        $this->assertSame('reserved', $guard->start());
        $this->assertSame('reserved', $guard->start());

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

        $this->assertSame('reserved', $guard->start());
        $this->assertSame('reserved', $guard->start());
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

        $this->assertSame('reserved', $guard->start());
        $this->assertNull($guard->start());

        $this->assertSame(1, $this->countMethod($redis, 'decr', 'ai-inflight'));
        $this->assertSame(1, $this->countMethod($redis, 'decr', $this->windowKey()));
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
