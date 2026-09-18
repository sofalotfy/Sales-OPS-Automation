<?php

namespace App\Enums;

/**
 * The four outcomes the scoring engine can produce for an inquiry (FR-001).
 *
 * Unlike the deprecated triage Disposition, this is advisory only: every
 * inquiry remains visible to a human reviewer regardless of classification
 * (FR-011). Mapped from the final weighted score via config('scoring.thresholds').
 */
enum Classification: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Disqualify = 'disqualify';

    /**
     * Map a clamped final score to a classification using the configured
     * thresholds with inclusive lower bounds (research R2 / contracts).
     */
    public static function forScore(float $score): self
    {
        $thresholds = [
            self::High->value => (float) config('scoring.thresholds.high', 75),
            self::Medium->value => (float) config('scoring.thresholds.medium', 55),
            self::Low->value => (float) config('scoring.thresholds.low', 30),
        ];

        foreach ($thresholds as $value => $threshold) {
            if ($score >= $threshold) {
                return self::from($value);
            }
        }

        return self::Disqualify;
    }
}