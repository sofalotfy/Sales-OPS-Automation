<?php

namespace Tests\Unit;

use App\Enums\Classification;
use App\Models\ClassificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassificationResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_read_round_trip_full_record(): void
    {
        $factorScores = [
            'scope_relevance' => ['score' => 88, 'weight' => 0.5, 'reasoning' => 'matches scope'],
            'urgency' => ['score' => 60, 'weight' => 0.2, 'reasoning' => 'asks for a call'],
        ];

        $created = ClassificationResult::create([
            'inquiry_message' => 'Do you offer annual maintenance contracts for heating boilers?',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_number' => '+1 555 0132',
            'company_name' => 'Example Corp',
            'country_region' => 'United Kingdom',
            'retrieved_context' => ['result_count' => 1, 'results' => [['rank' => 1]]],
            'factor_scores' => $factorScores,
            'dropped_factors' => [],
            'final_score' => 82.5,
            'classification' => Classification::High,
            'reasoning' => 'Weighted score 82.5 maps to high.',
        ]);

        $this->assertDatabaseHas('classification_results', [
            'id' => $created->getKey(),
            'inquiry_message' => 'Do you offer annual maintenance contracts for heating boilers?',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_number' => '+1 555 0132',
            'company_name' => 'Example Corp',
            'country_region' => 'United Kingdom',
            'classification' => 'high',
            'final_score' => 82.5,
        ]);

        /** @var ClassificationResult $read */
        $read = ClassificationResult::query()->findOrFail($created->getKey());

        $this->assertInstanceOf(Classification::class, $read->classification);
        $this->assertSame(Classification::High, $read->classification);
        $this->assertSame($factorScores, $read->factor_scores);
        $this->assertSame(['result_count' => 1, 'results' => [['rank' => 1]]], $read->retrieved_context);
        $this->assertSame([], $read->dropped_factors);
        $this->assertSame(82.5, $read->final_score);
    }

    public function test_classification_is_stored_as_its_string_value_and_cast_on_read(): void
    {
        ClassificationResult::create([
            'inquiry_message' => 'x',
            'retrieved_context' => ['result_count' => 0, 'results' => []],
            'factor_scores' => [],
            'dropped_factors' => [],
            'final_score' => 0,
            'classification' => Classification::Low,
        ]);

        $this->assertDatabaseHas('classification_results', ['classification' => 'low']);

        /** @var ClassificationResult $read */
        $read = ClassificationResult::query()->orderBy('id')->first();

        $this->assertSame(Classification::Low, $read->classification);
    }

    public function test_dropped_factors_round_trip(): void
    {
        $created = ClassificationResult::create([
            'inquiry_message' => 'x',
            'retrieved_context' => ['result_count' => 0, 'results' => []],
            'factor_scores' => [],
            'dropped_factors' => [['name' => 'budget_signal', 'reason' => 'AI provider unreachable']],
            'final_score' => 30,
            'classification' => Classification::Low,
            'reasoning' => null,
        ]);

        /** @var ClassificationResult $read */
        $read = ClassificationResult::query()->findOrFail($created->getKey());

        $this->assertSame(
            [['name' => 'budget_signal', 'reason' => 'AI provider unreachable']],
            $read->dropped_factors,
        );
        $this->assertNull($read->reasoning);
    }
}