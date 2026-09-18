<?php

namespace App\Scoring;

use App\Enums\Classification;
use Throwable;

/**
 * Main scoring aggregate (research R1–R3): iterate the code-registered factor
 * catalog, score each factor with its runtime weight, combine via the weighted
 * mean Σ(score·weight)/Σ(weight), clamp the result into [0,100], and map it to
 * a Classification through config('scoring.thresholds') (inclusive lower bounds).
 *
 * Failure semantics (FR-007/FR-008, research R3):
 *  - Empty catalog        → final_score 0.00 + `low` (clarification Q2).
 *  - A factor that throws → dropped, recorded in droppedFactors, remaining
 *    weights renormalize (they are simply excluded from numerator/denominator).
 *  - All factors dropped  → degraded to the empty-catalog classification (`low`).
 */
final class ScoringEngine
{
    public function __construct(
        private readonly FactorRegistry $registry,
        private readonly FactorScorer $scorer,
    ) {
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array<string, mixed>  $context
     */
    public function classify(array $inquiry, array $context): ClassificationOutcome
    {
        $factors = $this->registry->all();

        if ($factors === []) {
            return $this->emptyOutcome();
        }

        /** @var FactorScore[] $factorScores */
        $factorScores = [];
        /** @var array<int, array{name: string, reason: string}> $dropped */
        $dropped = [];

        $weightedSum = 0.0;
        $weightSum = 0.0;

        foreach ($factors as $name => $factor) {
            try {
                $score = $this->scorer->score($factor, $inquiry, $context);
                $factorScores[] = $score;
                $weightedSum += $score->weighted;
                $weightSum += $score->weight;
            } catch (Throwable $e) {
                $dropped[] = ['name' => $name, 'reason' => $e->getMessage()];
            }
        }

        if ($factorScores === []) {
            return new ClassificationOutcome(
                classification: $this->degradedClassification(),
                score: 0.0,
                factorScores: [],
                reasoning: 'All factors failed to compute; degraded to '.$this->degradedClassification()->value.'.',
                droppedFactors: $dropped,
            );
        }

        $score = round($weightSum > 0.0 ? $weightedSum / $weightSum : 0.0, 2);
        $score = max(0.0, min(100.0, $score));

        $classification = Classification::forScore($score);

        return new ClassificationOutcome(
            classification: $classification,
            score: (float) $score,
            factorScores: $factorScores,
            reasoning: sprintf('Weighted score %s maps to %s.', number_format($score, 2, '.', ''), $classification->value),
            droppedFactors: $dropped,
        );
    }

    private function emptyOutcome(): ClassificationOutcome
    {
        return new ClassificationOutcome(
            classification: $this->degradedClassification(),
            score: 0.0,
            factorScores: [],
            reasoning: 'No factors are registered; catalog is empty.',
            droppedFactors: [],
        );
    }

    private function degradedClassification(): Classification
    {
        return Classification::from((string) config('scoring.empty_catalog_classification', 'low'));
    }
}