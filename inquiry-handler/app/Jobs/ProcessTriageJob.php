<?php

namespace App\Jobs;

use App\Enums\Classification;
use App\Enums\InquiryRunStatus;
use App\Models\ClassificationResult;
use App\ScopeGate\ScopeCheckService;
use App\ScopeGate\ScopeVerdict;
use App\Scoring\ScoringEngine;
use App\Services\InquiryRunService;
use App\Triage\SystemPrompt;
use App\WebResearch\WebResearchService;
use App\WebResearch\WebResearchVerdict;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Redis-backed worker payload for POST /inquiry/enqueue (feature 013,
 * US1): replays the synchronous triage chain — web research → scope gate →
 * weighted multi-factor scoring — against one run row, advancing its status
 * per stage and writing each stage's columns as it completes.
 *
 * The run row is created by the enqueue endpoint (`queued`) with the payload
 * fields only; this job then updates it in place:
 *
 *   queued → processing → researching → scope_check → scoring → succeeded
 *                      ↘ decline: succeeded (disqualify envelope + refusal)
 *                      scope decline: succeeded (disqualify envelope + refusal)
 *   unrecoverable (last attempt): → failed (+ error)
 *
 * Idempotency (US4): a terminal row is left untouched, so re-delivered or
 * already-completed runs never re-run. Timeouts (US5): $timeout (590s) is
 * bounded under the worker's --timeout (600s) and the connection's
 * retry_after (REDIS_QUEUE_RETRY_AFTER, 900s), so a worker death mid-stage is
 * retried before the row is considered failed and never duplicates a delivery
 * past the worker timeout.
 */
class ProcessTriageJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 5;

    public array $backoff = [5, 15, 30, 60];

    /**
     * Crash-recovery ladder (US4 / research.md R3 / data-model.md §2):
     * redis `retry_after` (900) > worker `--timeout` (600) > job `$timeout`
     * (590). A job's lease is never released while an earlier attempt still
     * holds it, and a running job always finishes before the worker force-
     * kills it. Raising any research/AI budget requires raising all three in
     * concert — never only one.
     */
    public int $timeout = 590;

    public function __construct(public readonly int $inquiryRunId) {}

    public function handle(
        InquiryRunService $runs,
        WebResearchService $researchService,
        ScopeCheckService $scopeCheck,
        ScoringEngine $scoringEngine,
        SystemPrompt $systemPrompt,
    ): void {
        $record = $runs->findById($this->inquiryRunId);

        if ($record === null) {
            Log::warning('ProcessTriageJob: run no longer exists.', [
                'inquiry_run_id' => $this->inquiryRunId,
            ]);

            return;
        }

        if ($record->status?->isTerminal() === true) {
            // Already completed (re-delivery, manual completion, or a
            // time-out retry that finished elsewhere): never re-run (US4).
            Log::info('ProcessTriageJob: run already terminal, skipping.', [
                'inquiry_run_id' => $this->inquiryRunId,
                'status' => $record->status->value,
            ]);

            return;
        }

        try {
            $this->runStages($record, $runs, $researchService, $scopeCheck, $scoringEngine, $systemPrompt);
        } catch (Throwable $e) {
            // Mark failed only once the job is on its last attempt; earlier
            // attempts are retried by the queue (backoff ladder) and the poll
            // contract keeps serving the intermediate status.
            if ($this->attempts() >= $this->tries) {
                $runs->markFailed($this->inquiryRunId, $e->getMessage());
                Log::error('ProcessTriageJob: run failed after exhausting retries.', [
                    'inquiry_run_id' => $this->inquiryRunId,
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    private function runStages(
        ClassificationResult $record,
        InquiryRunService $runs,
        WebResearchService $researchService,
        ScopeCheckService $scopeCheck,
        ScoringEngine $scoringEngine,
        SystemPrompt $systemPrompt,
    ): void {
        $runId = (int) $record->id;
        $runs->markStatus($runId, InquiryRunStatus::Processing);

        $inquiry = $runs->inquiryFromRecord($record);

        if ((bool) config('web_research.enabled')) {
            $runs->markStatus($runId, InquiryRunStatus::Researching);

            $research = $researchService->run($inquiry);
            $verdict = $research['verdict'];
            $criteria = $research['criteria'];
            $audit = $research['audit'] ?? [];

            if ($verdict->isDecline()) {
                $reason = $verdict->reason;

                $runs->markSucceeded($runId, [
                    'retrieved_context' => ['result_count' => 0, 'results' => []],
                    'factor_scores' => [],
                    'dropped_factors' => [],
                    'final_score' => 0.0,
                    'classification' => Classification::Disqualify,
                    'reasoning' => $reason,
                    'web_research_outcome' => WebResearchVerdict::DECLINE,
                    'web_research_reason' => $reason,
                    'web_research' => [
                        'criteria' => $criteria,
                        'findings' => $verdict->research,
                        'audit' => null,
                    ],
                    'system_prompt' => $systemPrompt->content(),
                    'refusal' => $verdict->refusal ?? $reason,
                ]);

                return;
            }

            $runs->update($runId, [
                'web_research_outcome' => $verdict->outcome,
                'web_research_reason' => $verdict->reason,
                'web_research' => [
                    'criteria' => $criteria,
                    'findings' => $verdict->research,
                    'audit' => $audit,
                ],
            ]);
        }

        if ((bool) config('scope_gate.enabled')) {
            $runs->markStatus($runId, InquiryRunStatus::ScopeCheck);

            $scope = $scopeCheck->check($inquiry);
            $verdict = $scope['verdict'];

            if ($verdict->isDecline()) {
                $reason = $verdict->reason;

                $runs->markSucceeded($runId, [
                    'retrieved_context' => $scope['retrieved'] ?? ['result_count' => 0, 'results' => []],
                    'factor_scores' => [],
                    'dropped_factors' => [],
                    'final_score' => 0.0,
                    'classification' => Classification::Disqualify,
                    'reasoning' => $reason,
                    'scope_check_outcome' => ScopeVerdict::DECLINE,
                    'scope_check_reason' => $reason,
                    'system_prompt' => $systemPrompt->content(),
                    'refusal' => $verdict->refusal ?? $reason,
                ]);

                return;
            }

            $runs->update($runId, [
                'scope_check_outcome' => $verdict->outcome,
                'scope_check_reason' => $verdict->reason,
            ]);
        }

        // Scoring: rebuild the same context the synchronous triage service
        // uses (inquiry + fixed system prompt + any stage sections), advance
        // the status, and land the result fields exactly once.
        $record->refresh();

        $context = [
            'inquiry' => $inquiry,
            'retrieved_context' => ['result_count' => 0, 'results' => []],
            'system_prompt' => $systemPrompt->content(),
        ];

        if ($record->scope_check_outcome !== null) {
            $context['scope_check'] = [
                'outcome' => $record->scope_check_outcome,
                'reason' => $record->scope_check_reason,
            ];
        }

        if ($record->web_research_outcome !== null) {
            $webResearch = $record->web_research;
            $context['web_research'] = [
                'outcome' => $record->web_research_outcome,
                'reason' => $record->web_research_reason,
                'criteria' => is_array($webResearch) ? ($webResearch['criteria'] ?? []) : [],
                'findings' => is_array($webResearch) ? ($webResearch['findings'] ?? []) : [],
            ];
        }

        $runs->markStatus($runId, InquiryRunStatus::Scoring);

        $outcome = $scoringEngine->classify($inquiry, $context);

        $runs->markSucceeded($runId, [
            'retrieved_context' => ['result_count' => 0, 'results' => []],
            'factor_scores' => $outcome->factorScoresArray(),
            'dropped_factors' => $outcome->droppedFactors,
            'final_score' => $outcome->score,
            'classification' => $outcome->classification,
            'reasoning' => $outcome->reasoning,
            'system_prompt' => $systemPrompt->content(),
            'refusal' => null,
        ]);
    }
}
