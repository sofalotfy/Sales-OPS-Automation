<?php

namespace App\Scoring;

/**
 * One contributing factor's run inside the engine (data-model.md / R10).
 * `weighted` is score × weight; the engine divides the sum of `weighted` by
 * the sum of `weight` to get the final 0–100 score (normalization at
 * combination time, R1).
 */
final class FactorScore
{
    public function __construct(
        public readonly string $factor,
        public readonly int $score,
        public readonly float $weight,
        public readonly float $weighted,
        public readonly string $reasoning,
    ) {
    }

    /**
     * Response/storage shape: `{ "<factor>": {score, weight, reasoning} }`
     * (contracts/inquiry-web.md). The factor name is the array key, not a value.
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'weight' => $this->weight,
            'reasoning' => $this->reasoning,
        ];
    }
}