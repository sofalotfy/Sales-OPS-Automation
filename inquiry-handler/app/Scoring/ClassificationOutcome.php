<?php

namespace App\Scoring;

use App\Enums\Classification;

/**
 * Immutable result of one scoring pass (data-model.md): the final weighted
 * score, the mapped Classification, every contributing FactorScore, and the
 * dropped factors that were excluded (FR-007).
 */
final class ClassificationOutcome
{
    /**
     * @param  FactorScore[]  $factorScores
     * @param  array<int, array{name: string, reason: string}>  $droppedFactors
     */
    public function __construct(
        public readonly Classification $classification,
        public readonly float $score,
        public readonly array $factorScores,
        public readonly string $reasoning,
        public readonly array $droppedFactors = [],
    ) {
    }

    /**
     * @return array<string, array{score: int, weight: float, reasoning: string}>
     */
    public function factorScoresArray(): array
    {
        $map = [];
        foreach ($this->factorScores as $score) {
            $map[$score->factor] = $score->toArray();
        }

        return $map;
    }
}