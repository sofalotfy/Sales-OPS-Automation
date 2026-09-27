<?php

namespace App\Services;

use App\Enums\InquiryRunStatus;
use App\Models\ClassificationResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
     * `status` and `classification` are optional exact-match filters; null (or an
     * empty string) means unfiltered. `total` counts the filtered set, not the
     * whole log, so a caller paginating a filtered view cannot walk off the end
     * of a page it is not actually reading.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     *
     * @throws Throwable when the scoped store is unreadable
     */
    public function list(
        int $limit,
        int $offset,
        ?string $status = null,
        ?string $classification = null,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $status = ($status === null || $status === '') ? null : $status;
        $classification = ($classification === null || $classification === '') ? null : $classification;

        // A run still in the pipeline has no verdict yet, so a classification
        // filter alongside it can only match nothing. Ignoring it answers the
        // question the caller actually asked ("what is still scoring?") instead
        // of reporting an empty log. An unknown status keeps the
        // classification: validation belongs to the controller, and dropping a
        // filter on a value this method cannot judge would be a silent guess.
        if (InquiryRunStatus::tryFrom((string) $status)?->awaitsClassification() === true) {
            $classification = null;
        }

        $query = $this->withinRange(ClassificationResult::query(), $from, $to);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($classification !== null) {
            $query->where('classification', $classification);
        }

        $total = (clone $query)->count();

        $items = $query
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
     * `avg_scored_score` deliberately averages only the runs that produced a
     * usable score. A `final_score` of 0 is an honest "no signal" (the factor
     * found nothing, or the catalog was empty), not a bad lead, so blending
     * those rows into one mean reports research coverage as lead quality. On
     * the current log that drags 55.77 down to 14.44. Consumers that want the
     * blended figure can still compute it from `scored`/`no_signal` if they
     * truly want it.
     *
     * @return array{
     *     total: int,
     *     by_status: array<string, int>,
     *     by_classification: array<string, int>,
     *     by_classification_no_signal: array<string, int>,
     *     refusals: int,
     *     scored: int,
     *     no_signal: int,
     *     avg_scored_score: float|null,
     *     scored_kept: int,
     *     no_signal_kept: int
     * }
     *
     * @throws Throwable when the scoped store is unreadable
     */
    /**
     * Restricts a query to runs created inside the given calendar days, inclusive.
     *
     * The upper bound is half-open (`< to + 1 day`) rather than `<= to 23:59:59`
     * so a run stamped at the very last microsecond of the end day is still
     * inside the range, instead of falling outside a `23:59:59` ceiling on a
     * microsecond-precision column.
     *
     * The bounds are UTC because that is the app timezone and the column is
     * stored in it, so "the 27th" means the same thing to the handler and to
     * the dashboard rendering the picker.
     *
     * @param  Builder<ClassificationResult>  $query
     */
    private function withinRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from !== null) {
            $query->where('created_at', '>=', $from.' 00:00:00');
        }

        if ($to !== null) {
            $end = CarbonImmutable::parse($to, 'UTC')->addDay()->startOfDay();

            $query->where('created_at', '<', $end->toDateTimeString());
        }

        return $query;
    }

    public function stats(?string $from = null, ?string $to = null): array
    {
        // The range is applied before `toBase()` so the aggregates below inherit
        // it. Every count in the payload comes from `$base`, so scoping that one
        // query is what keeps the cards, the mix, and the total describing the
        // same window instead of the whole log beside a filtered list.
        $base = $this->withinRange(ClassificationResult::query(), $from, $to)->toBase();

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

        // Per-verdict zero-score counts, so a consumer can reconcile the verdict
        // mix against the headline `no_signal` total. These are different cuts:
        // a run can score 0 and still be recorded as `low` (the empty-catalog
        // rule maps 0 to low, never disqualify), and a gate decline scores 0 by
        // construction. Without this, the mix and the zero-signal count look like
        // they contradict each other. Verdicts with no zero-score runs are
        // omitted rather than reported as an explicit 0.
        $byClassificationNoSignal = [];
        foreach ((clone $base)
            ->whereNotNull('classification')
            ->where('final_score', 0)
            ->selectRaw('classification, count(*) as total')
            ->groupBy('classification')
            ->get() as $row) {
            $byClassificationNoSignal[(string) $row->classification] = (int) $row->total;
        }

        // A NULL score is a run that has not been scored yet (still in flight),
        // which is a third state distinct from "scored zero", so it is counted
        // only via `total` and never folded into either bucket below.
        $scored = (clone $base)->where('final_score', '>', 0)->count();

        // Postgres returns avg() as a bare numeric and JSON cannot distinguish a
        // whole float from an int, so the average is rounded to a fixed 2dp for
        // a stable, display-ready number.
        $avgScoredScore = (clone $base)->where('final_score', '>', 0)->avg('final_score');

        // The two "kept" buckets, split on whether the run produced a usable
        // score. Together with `by_classification.disqualify`, the terminal
        // `by_status.failed` count and the non-terminal statuses these partition
        // the whole log, so a dashboard can render every run exactly once:
        //
        //   scored_kept + no_signal_kept + disqualify + failed + in_flight == total
        //
        // Scoping to `status = succeeded` is what keeps a crashed run out of both
        // kept buckets (it is already counted as failed). A NULL verdict counts
        // as kept so a succeeded-but-unverdicted row cannot slip between buckets
        // and break the partition.
        $kept = (clone $base)
            ->where('status', 'succeeded')
            ->where(function ($query): void {
                $query->whereNull('classification')->orWhere('classification', '<>', 'disqualify');
            });

        $scoredKept = (clone $kept)->where('final_score', '>', 0)->count();
        $noSignalKept = (clone $kept)->where('final_score', 0)->count();

        return [
            'total' => (int) (clone $base)->count(),
            'by_status' => $byStatus,
            'by_classification' => $byClassification,
            'by_classification_no_signal' => $byClassificationNoSignal,
            'refusals' => (int) (clone $base)
                ->whereNotNull('refusal')
                ->where('refusal', '<>', '')
                ->count(),
            'scored' => (int) $scored,
            'no_signal' => (int) (clone $base)->where('final_score', 0)->count(),
            'avg_scored_score' => $avgScoredScore === null ? null : round((float) $avgScoredScore, 2),
            'scored_kept' => (int) $scoredKept,
            'no_signal_kept' => (int) $noSignalKept,
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
