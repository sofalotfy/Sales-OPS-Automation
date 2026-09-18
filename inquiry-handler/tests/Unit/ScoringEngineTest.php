<?php

namespace Tests\Unit;

use App\Enums\Classification;
use App\Models\Factor;
use App\Scoring\FactorRegistry;
use App\Scoring\FactorScorer;
use App\Scoring\ScoreFactor;
use App\Scoring\ScoringEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Support\ConfigurableFactor;

class ScoringEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_catalog_returns_low_with_zero_score(): void
    {
        $outcome = $this->engine()->classify(['message' => 'x'], []);

        $this->assertSame('low', $outcome->classification->value);
        $this->assertSame(0.0, $outcome->score);
        $this->assertSame([], $outcome->factorScoresArray());
        $this->assertSame([], $outcome->droppedFactors);
        $this->assertSame('No factors are registered; catalog is empty.', $outcome->reasoning);
    }

    public function test_weighted_mean_math(): void
    {
        $engine = $this->engine(
            new ConfigurableFactor('a', 100),
            new ConfigurableFactor('b', 20),
        );

        // 100 (w1) + 20 (w1) → 60.0 → medium
        $outcome = $engine->classify(['message' => 'x'], []);

        $this->assertSame(60.0, $outcome->score);
        $this->assertSame('medium', $outcome->classification->value);
    }

    public function test_uneven_weights_are_respected(): void
    {
        Factor::instance()->update(['weights' => ['a' => 1.0, 'b' => 3.0]]);

        $engine = $this->engine(
            new ConfigurableFactor('a', 100),
            new ConfigurableFactor('b', 20),
        );

        // (100*1 + 20*3) / (1 + 3) = 40.0 → low
        $outcome = $engine->classify(['message' => 'x'], []);

        $this->assertSame(40.0, $outcome->score);
        $this->assertSame('low', $outcome->classification->value);

        $scores = $outcome->factorScoresArray();
        $this->assertSame(100, $scores['a']['score']);
        $this->assertSame(1.0, $scores['a']['weight']);
        $this->assertSame(20, $scores['b']['score']);
        $this->assertSame(3.0, $scores['b']['weight']);
    }

    public function test_inclusive_threshold_boundaries(): void
    {
        $this->assertSame(Classification::High, Classification::forScore(75.0));
        $this->assertSame(Classification::Medium, Classification::forScore(74.99));
        $this->assertSame(Classification::Medium, Classification::forScore(55.0));
        $this->assertSame(Classification::Low, Classification::forScore(54.99));
        $this->assertSame(Classification::Low, Classification::forScore(30.0));
        $this->assertSame(Classification::Disqualify, Classification::forScore(29.99));
    }

    public function test_engine_maps_score_through_thresholds(): void
    {
        // Single factor at weight 1.0: final score == factor score.
        $high = $this->engine(new ConfigurableFactor('a', 75))->classify(['message' => 'x'], []);

        $this->assertSame(75.0, $high->score);
        $this->assertSame('high', $high->classification->value);

        $low = $this->engine(new ConfigurableFactor('a', 30))->classify(['message' => 'x'], []);

        $this->assertSame(30.0, $low->score);
        $this->assertSame('low', $low->classification->value);

        $disq = $this->engine(new ConfigurableFactor('a', 29))->classify(['message' => 'x'], []);

        $this->assertSame('disqualify', $disq->classification->value);
    }

    public function test_out_of_range_scores_are_clamped(): void
    {
        $over = $this->engine(new ConfigurableFactor('a', 150))->classify(['message' => 'x'], []);
        $under = $this->engine(new ConfigurableFactor('a', -50))->classify(['message' => 'x'], []);

        $this->assertSame(100, $over->factorScoresArray()['a']['score']);
        $this->assertSame(0, $under->factorScoresArray()['a']['score']);
        $this->assertSame(100.0, $over->score);
        $this->assertSame(0.0, $under->score);
        $this->assertSame('high', $over->classification->value);
        $this->assertSame('disqualify', $under->classification->value);
    }

    public function test_failing_factor_is_dropped_and_remaining_weights_renormalize(): void
    {
        $engine = $this->engine(
            new ConfigurableFactor('a', null, 'boom', new RuntimeException('provider down')),
            new ConfigurableFactor('b', 80),
        );

        $outcome = $engine->classify(['message' => 'x'], []);

        // b alone at weight 1.0 → 80. If 'a' were (incorrectly) included at 0
        // the mean would be 40. Renormalization is the (80*1)/(1) behavior.
        $this->assertSame(80.0, $outcome->score);
        $this->assertSame('high', $outcome->classification->value);
        $this->assertSame([['name' => 'a', 'reason' => 'provider down']], $outcome->droppedFactors);
        $this->assertArrayNotHasKey('a', $outcome->factorScoresArray());
    }

    public function test_all_factors_failing_degrades_to_low(): void
    {
        $engine = $this->engine(
            new ConfigurableFactor('a', null, 'boom', new RuntimeException('x')),
            new ConfigurableFactor('b', null, 'boom', new RuntimeException('y')),
        );

        $outcome = $engine->classify(['message' => 'x'], []);

        $this->assertSame('low', $outcome->classification->value);
        $this->assertSame(0.0, $outcome->score);
        $this->assertCount(2, $outcome->droppedFactors);
        $this->assertSame([], $outcome->factorScoresArray());
    }

    public function test_unknown_stored_weight_keys_are_ignored(): void
    {
        Factor::instance()->update([
            'weights' => ['a' => 1.0, 'made_up_factor' => 9],
        ]);

        $outcome = $this->engine(new ConfigurableFactor('a', 60))->classify(['message' => 'x'], []);

        $this->assertSame(60.0, $outcome->score);
    }

    public function test_adding_a_factor_requires_no_engine_change(): void
    {
        // SC-003 pluggability: register one factor, see it in factor_scores.
        $registry = new FactorRegistry;
        $registry->add(new ConfigurableFactor('scope_relevance', 88, 'message matches served scope'));

        Factor::instance()->update(['weights' => ['scope_relevance' => 0.5]]);

        $engine = new ScoringEngine($registry, new FactorScorer);

        $outcome = $engine->classify(['message' => 'x'], ['retrieved_context' => ['result_count' => 0, 'results' => []]]);

        $scores = $outcome->factorScoresArray();

        $this->assertArrayHasKey('scope_relevance', $scores);
        $this->assertSame(88, $scores['scope_relevance']['score']);
        $this->assertSame(0.5, $scores['scope_relevance']['weight']);
        $this->assertSame('message matches served scope', $scores['scope_relevance']['reasoning']);
        $this->assertSame(88.0, $outcome->score);
    }

    public function test_unreadable_weights_store_falls_back_to_default_weight(): void
    {
        // Simulate store unavailability by pointing the model at a missing table
        // (DropTable trick): Factor::instance() throws → scorer falls back to 1.0.
        \Illuminate\Support\Facades\Schema::drop('factor_settings');

        $outcome = $this->engine(new ConfigurableFactor('a', 60))->classify(['message' => 'x'], []);

        $this->assertSame(60.0, $outcome->score);
        $this->assertSame(1.0, $outcome->factorScoresArray()['a']['weight']);
    }

    private function engine(ScoreFactor ...$factors): ScoringEngine
    {
        $registry = new FactorRegistry;
        foreach ($factors as $factor) {
            $registry->add($factor);
        }

        return new ScoringEngine($registry, new FactorScorer);
    }
}