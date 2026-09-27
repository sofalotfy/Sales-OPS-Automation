<?php

namespace App\Services;

use App\Models\ClassificationResult;
use Throwable;

/**
 * Read-only reporting over the append-only classification log
 * (contracts/classification-reporting.md). The dashboard consumes these
 * endpoints over HTTP; it never touches the scoped store directly (SC-006).
 *
 * Since feature 013 rows are created early and completed progressively, list()
 * returns EVERY run (not only finished ones) with its status, and detail rows
 * tolerate the result columns (classification, factors, score) still being
 * null until their stage lands (the dashboard renders those as pending/—).
 *
 * Store unavailability is NOT masked: callers (the admin controller) map
 * thrown errors to a 503 so the dashboard never mistakes a dead log for an
 * empty one.
 */
class ClassificationResultsService
{
    /**
     * Newest-first page of summary rows (big `retrieved_context` /
     * `factor_scores` are intentionally omitted; the detail endpoint ships them).
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     *
     * @throws Throwable when the scoped store is unreadable
     */
    public function list(int $limit, int $offset): array
    {
        $total = ClassificationResult::count();

        $items = ClassificationResult::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (ClassificationResult $result) => $this->summarize($result))
            ->values()
            ->all();

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * Whole-log aggregates for the dashboard landing page. `list()` can only
     * describe one page (capped at 50 rows), so status/classification counts
     * taken from it would silently under-report once the log outgrows a page.
     *
     * Aggregated on the query builder rather than the model so the
     * `InquiryRunStatus` / `Classification` enum casts on those columns do not
     * interfere with grouping, and `final_score` is averaged as a raw column
     * for the same reason.
     *
     * @return array{
     *     total: int,
     *     by_status: array<string, int>,
     *     by_classification: array<string, int>,
     *     refusals: int,
     *     avg_score: float|null
     * }
     *
     * @throws Throwable when the scoped store is unreadable
     */
    public function stats(): array
    {
        $base = ClassificationResult::query()->toBase();

        $byStatus = [];
        foreach ((clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            // `status` is nullable and rows created before the lifecycle columns
            // defaulted to succeeded via backfill, so group any stragglers under
            // an explicit "unknown" rather than emitting an empty JSON key.
            $byStatus[(string) ($row->status ?? 'unknown')] = (int) $row->total;
        }

        $byClassification = [];
        foreach ((clone $base)
            ->whereNotNull('classification')
            ->selectRaw('classification, count(*) as total')
            ->groupBy('classification')
            ->get() as $row) {
            $byClassification[(string) $row->classification] = (int) $row->total;
        }

        // Postgres returns avg() as a bare numeric and JSON cannot distinguish a
        // whole float from an int, so the average is rounded to a fixed 2dp for
        // a stable, display-ready number.
        $avgScore = (clone $base)->avg('final_score');

        return [
            'total' => (int) (clone $base)->count(),
            'by_status' => $byStatus,
            'by_classification' => $byClassification,
            'refusals' => (int) (clone $base)
                ->whereNotNull('refusal')
                ->where('refusal', '<>', '')
                ->count(),
            'avg_score' => $avgScore === null ? null : round((float) $avgScore, 2),
        ];
    }

    /**
     * Full row for one classification run; null when the id does not exist.
     *
     * @return array<string, mixed>|null
     *
     * @throws Throwable when the scoped store is unreadable
     */
    public function find(int $id): ?array
    {
        $result = ClassificationResult::find($id);

        if ($result === null) {
            return null;
        }

        return [
            'id' => $result->id,
            'inquiry_message' => $result->inquiry_message,
            'first_name' => $result->first_name,
            'last_name' => $result->last_name,
            'email' => $result->email,
            'phone_number' => $result->phone_number,
            'company_name' => $result->company_name,
            'country_region' => $result->country_region,
            'campaign_id' => $result->campaign_id,
            'lead_id' => $result->lead_id,
            'status' => $result->status?->value,
            'classification' => $result->classification?->value,
            'final_score' => $result->final_score,
            'error' => $result->error,
            'reasoning' => $result->reasoning,
            'factor_scores' => $result->factor_scores,
            'dropped_factors' => $result->dropped_factors,
            'retrieved_context' => $result->retrieved_context,
            'scope_check_outcome' => $result->scope_check_outcome,
            'scope_check_reason' => $result->scope_check_reason,
            'refusal' => $result->refusal,
            'web_research_outcome' => $result->web_research_outcome,
            'web_research_reason' => $result->web_research_reason,
            'web_research' => $result->web_research,
            'system_prompt' => $result->system_prompt,
            'created_at' => $result->created_at?->toIso8601String(),
            'updated_at' => $result->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(ClassificationResult $result): array
    {
        return [
            'id' => $result->id,
            'campaign_id' => $result->campaign_id,
            'lead_id' => $result->lead_id,
            'status' => $result->status?->value,
            'classification' => $result->classification?->value,
            'final_score' => $result->final_score,
            'error' => $result->error,
            'inquiry_message' => $this->truncate((string) $result->inquiry_message, 160),
            'first_name' => $result->first_name,
            'last_name' => $result->last_name,
            'email' => $result->email,
            'reasoning' => $this->truncate((string) $result->reasoning, 200),
            'scope_check_outcome' => $result->scope_check_outcome,
            'web_research_outcome' => $result->web_research_outcome,
            'created_at' => $result->created_at?->toIso8601String(),
            'updated_at' => $result->updated_at?->toIso8601String(),
        ];
    }

    private function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $length - 1)).'…';
    }
}
