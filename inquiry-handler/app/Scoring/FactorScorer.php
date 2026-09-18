<?php

namespace App\Scoring;

use App\Models\Factor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Governs one factor run: resolve the runtime weight (stored value from the
 * factor-settings singleton, falling back to config('scoring.default_factor_weight')),
 * invoke the score factor, clamp the declared score into [score_min, score_max],
 * and produce a FactorScore (research R4/R11).
 *
 * A factor that throws is NOT scored here — the engine catches that and drops
 * it with renormalization (FR-007). This class only guards the weights-store
 * read: when the store is unreadable it logs and proceeds with the default
 * weight (research R3c), so classification always completes.
 */
final class FactorScorer
{
    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array<string, mixed>  $context
     */
    public function score(ScoreFactor $factor, array $inquiry, array $context): FactorScore
    {
        $name = $factor->name();
        $weight = $this->weightFor($name);

        $verdict = $factor->score($inquiry, $context);

        $score = $this->clamp($verdict->score);

        return new FactorScore(
            factor: $name,
            score: $score,
            weight: $weight,
            weighted: (float) round($weight * $score, 4),
            reasoning: $verdict->reasoning,
        );
    }

    private function weightFor(string $name): float
    {
        $default = (float) config('scoring.default_factor_weight', 1.0);

        try {
            $stored = Factor::instance()->weightFor($name);
        } catch (Throwable $e) {
            Log::error('Factor settings store unreadable; using default weight.', [
                'factor' => $name,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }

        if ($stored === null) {
            return $default;
        }

        // Stored weights are non-negative decimals; a corrupt negative value is
        // ignored in favor of the default (zero is allowed: disables a factor).
        return $stored >= 0 ? $stored : $default;
    }

    private function clamp(int $score): int
    {
        $min = (int) config('scoring.score_min', 0);
        $max = (int) config('scoring.score_max', 100);

        return max($min, min($max, $score));
    }
}