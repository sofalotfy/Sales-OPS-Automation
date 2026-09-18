<?php

namespace App\Scoring;

/**
 * Immutable result of one factor computation (FR-002 / research R4).
 * Scores are declared integers in [0, 100]; the scorer clamps any
 * out-of-range value defensively (R3e).
 */
final class FactorVerdict
{
    /**
     * @param  array<string, mixed>  $meta  optional machine-readable detail
     */
    public function __construct(
        public readonly int $score,
        public readonly string $reasoning,
        public readonly array $meta = [],
    ) {
    }
}