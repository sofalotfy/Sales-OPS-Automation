<?php

namespace App\Scoring;

use Countable;
use IteratorAggregate;

/**
 * Dev-only, code-registered catalog of factors (FR-004 / research R4).
 *
 * The factor SET is registered here in code (AppServiceProvider), starting
 * empty for this feature and growing one factor at a time as a developer
 * writes a `ScoreFactor` service and calls `add()`. Because the set is not
 * DB-backed, runtime can never add/remove factors — the dashboard can only
 * tune weights of names that already exist in this registry.
 *
 * @implements IteratorAggregate<string, ScoreFactor>
 */
class FactorRegistry implements IteratorAggregate, Countable
{
    /** @var array<string, ScoreFactor> */
    private array $factors = [];

    public function add(ScoreFactor $factor): void
    {
        $this->factors[$factor->name()] = $factor;
    }

    /**
     * @return array<string, ScoreFactor>
     */
    public function all(): array
    {
        return $this->factors;
    }

    public function has(string $name): bool
    {
        return isset($this->factors[$name]);
    }

    public function get(string $name): ?ScoreFactor
    {
        return $this->factors[$name] ?? null;
    }

    public function count(): int
    {
        return count($this->factors);
    }

    /**
     * @return \Traversable<string, ScoreFactor>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->factors;
    }
}