<?php

namespace Tests\Feature;

use App\Enums\Classification;
use App\Services\ClassificationResultsService;
use App\Services\InquiryRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Classification logging lifecycle (feature 013, data-model.md): rows are now
 * created early (`queued`, result columns still null) and completed
 * progressively, so the reporting service lists every run with its status and
 * tolerates the result columns still being missing.
 */
class ClassificationResultsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.crm_key' => 'crm-secret']);
    }

    private function enqueue(string $campaign, string $lead, string $message): int
    {
        $result = $this->app->make(InquiryRunService::class)->enqueue($campaign, $lead, [
            'first_name' => 'Sara',
            'last_name' => 'Ali',
            'email' => 'sara.ali@example.store',
            'message' => $message,
        ]);

        return (int) $result['record']->id;
    }

    public function test_list_includes_progressive_and_completed_rows_with_status(): void
    {
        $queued = $this->enqueue('camp-a', 'lead-a', 'Queued only.');

        $this->app->make(InquiryRunService::class)->markSucceeded(
            $this->enqueue('camp-b', 'lead-b', 'Finished run.'),
            [
                'retrieved_context' => ['result_count' => 0, 'results' => []],
                'factor_scores' => [],
                'dropped_factors' => [],
                'final_score' => 0.0,
                'classification' => Classification::Disqualify,
                'reasoning' => 'n/a',
                'refusal' => null,
            ],
        );

        $page = $this->app->make(ClassificationResultsService::class)->list(50, 0);

        $this->assertSame(2, $page['total']);
        $this->assertCount(2, $page['items']);

        $byId = array_column($page['items'], null, 'id');
        $this->assertSame('queued', $byId[$queued]['status']);
        $this->assertNull($byId[$queued]['classification']);
        $this->assertNull($byId[$queued]['final_score']);
        $this->assertSame('camp-a', $byId[$queued]['campaign_id']);
        $this->assertSame('lead-a', $byId[$queued]['lead_id']);

        $completed = array_values(array_diff(array_keys($byId), [$queued]))[0];
        $this->assertSame('succeeded', $byId[$completed]['status']);
        $this->assertSame('disqualify', $byId[$completed]['classification']);
    }

    public function test_find_tolerates_result_columns_still_missing(): void
    {
        $id = $this->enqueue('camp-a', 'lead-a', 'Still running.');

        $row = $this->app->make(ClassificationResultsService::class)->find($id);

        $this->assertIsArray($row);
        $this->assertSame('queued', $row['status']);
        $this->assertNull($row['classification']);
        $this->assertNull($row['final_score']);
        $this->assertNull($row['factor_scores']);
        $this->assertNull($row['error']);
        $this->assertSame('camp-a', $row['campaign_id']);
        $this->assertSame('lead-a', $row['lead_id']);
    }

    public function test_find_returns_unknown_as_null(): void
    {
        $this->assertNull($this->app->make(ClassificationResultsService::class)->find(424242));
    }
}
