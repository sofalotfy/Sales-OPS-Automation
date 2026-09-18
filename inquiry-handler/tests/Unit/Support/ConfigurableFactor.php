<?php

namespace Tests\Unit\Support;

use App\Scoring\FactorVerdict;
use App\Scoring\ScoreFactor;
use Throwable;

/**
 * Configurable ScoreFactor stub for scoring tests (SC-003 pluggability proof):
 * the test picks the name/score/reasoning and can force a throw.
 */
class ConfigurableFactor implements ScoreFactor
{
    public function __construct(
        private readonly string $name,
        private readonly ?int $score = null,
        private readonly string $reasoning = 'stub reasoning',
        private readonly ?Throwable $throw = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function score(array $inquiry, array $context): FactorVerdict
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new FactorVerdict($this->score ?? 0, $this->reasoning);
    }
}