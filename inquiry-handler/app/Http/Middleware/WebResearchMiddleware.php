<?php

namespace App\Http\Middleware;

use App\Enums\Classification;
use App\Enums\InquiryRunStatus;
use App\Models\ClassificationResult;
use App\Services\InquiryRunService;
use App\Triage\Exceptions\MessageValidationException;
use App\Triage\MessageExtractor;
use App\Triage\SystemPrompt;
use App\WebResearch\WebResearchService;
use App\WebResearch\WebResearchVerdict;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Pre-classification web-research step.
 *
 * Runs BEFORE the scope gate on the public POST /inquiry/triage path: look up
 * public information about the inquirer's company and person, attach the
 * verdict + criteria to the request so scoring factors and the persisted
 * record can use them, then:
 *  - accept → pass the request through enriched (findings available to the
 *    classification flow via `web_research` request attributes → context);
 *  - decline → stop before classification, persist the refusal record and
 *    return a same-shape refusal response with the reason;
 *  - indeterminate → fail open: pass through unchanged (provider unavailable).
 *
 * The step never screens an invalid payload: a non-object JSON body or an
 * extraction/validation failure falls through so the controller emits the
 * existing 400/422 unchanged. When web_research.enabled is false the step is
 * fully bypassed and no web_research marker is produced.
 *
 * Since feature 013 the run row is opened earlier (BeginInquiryRun) and this
 * step PROGRESSIVELY writes to it: `researching` is set before research runs,
 * the research columns land on completion, and a decline completes the same
 * row `succeeded` with a disqualify envelope + refusal. Without a staged row
 * (`inquiry_run_id` absent) the step keeps its pre-feature create-on-decline
 * behavior.
 */
class WebResearchMiddleware
{
    public function __construct(
        private readonly MessageExtractor $extractor,
        private readonly WebResearchService $researchService,
        private readonly SystemPrompt $systemPrompt,
        private readonly InquiryRunService $runs,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('web_research.enabled')) {
            return $next($request);
        }

        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $next($request);
        }

        try {
            $inquiry = $this->extractor->extract($decoded);
        } catch (MessageValidationException) {
            return $next($request);
        }

        $runId = $this->runId($request);
        if ($runId !== null) {
            $this->runs->markStatus($runId, InquiryRunStatus::Researching);
        }

        $result = $this->researchService->run($inquiry);
        $verdict = $result['verdict'];

        $request->attributes->set('web_research_verdict', $verdict);
        $request->attributes->set('web_research_criteria', $result['criteria']);
        $request->attributes->set('web_research_audit', $result['audit'] ?? []);

        if ($verdict->isDecline()) {
            return $this->declined($request, $inquiry, $result);
        }

        if ($runId !== null) {
            $this->persistProgress($runId, $verdict, $result);
        }

        return $next($request);
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array{verdict: WebResearchVerdict, criteria: array<string, mixed>}  $result
     */
    private function declined(Request $request, array $inquiry, array $result): JsonResponse
    {
        $verdict = $result['verdict'];
        $reason = $verdict->reason;
        $refusal = $verdict->refusal ?? $reason;
        $criteria = $result['criteria'];

        $fields = [
            'inquiry_message' => $inquiry['message'],
            'first_name' => $inquiry['first_name'],
            'last_name' => $inquiry['last_name'],
            'email' => $inquiry['email'],
            'phone_number' => $inquiry['phone_number'] ?? null,
            'company_name' => $inquiry['company_name'] ?? null,
            'country_region' => $inquiry['country_region'] ?? null,
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
            'system_prompt' => $this->systemPrompt->content(),
            'refusal' => $refusal,
        ];

        $this->persist($request, $fields);

        return response()->json([
            'classification' => Classification::Disqualify->value,
            'score' => 0.0,
            'factor_scores' => [],
            'dropped_factors' => [],
            'reply' => $refusal,
            'reasoning' => $reason,
            'context' => [
                'inquiry' => [
                    'first_name' => $inquiry['first_name'],
                    'last_name' => $inquiry['last_name'],
                    'email' => $inquiry['email'],
                    'phone_number' => $inquiry['phone_number'] ?? null,
                    'company_name' => $inquiry['company_name'] ?? null,
                    'country_region' => $inquiry['country_region'] ?? null,
                    'message' => $inquiry['message'],
                ],
                'system_prompt' => $this->systemPrompt->content(),
                'retrieved_context' => ['result_count' => 0, 'results' => []],
                'web_research' => [
                    'outcome' => WebResearchVerdict::DECLINE,
                    'reason' => $reason,
                    'criteria' => $criteria,
                    'findings' => $verdict->research,
                ],
            ],
        ]);
    }

    /**
     * Land the research columns on the staged run after an accept /
     * indeterminate pass-through.
     *
     * @param  array{verdict: WebResearchVerdict, criteria: array<string, mixed>}  $result
     */
    private function persistProgress(int $runId, WebResearchVerdict $verdict, array $result): void
    {
        $this->runs->update($runId, [
            'web_research_outcome' => $verdict->outcome,
            'web_research_reason' => $verdict->reason,
            'web_research' => [
                'criteria' => $result['criteria'],
                'findings' => $verdict->research,
                'audit' => $result['audit'] ?? [],
            ],
        ]);
    }

    /**
     * Complete the staged run (or, when none was staged, create the declined
     * row) so a decline is never lost even when BeginInquiryRun failed.
     *
     * @param  array<string, mixed>  $fields
     */
    private function persist(Request $request, array $fields): void
    {
        $runId = $this->runId($request);

        if ($runId !== null) {
            $this->runs->markSucceeded($runId, $fields);

            return;
        }

        try {
            ClassificationResult::create($fields + ['status' => InquiryRunStatus::Succeeded]);
        } catch (Throwable $e) {
            Log::error('Failed to persist declined web-research record.', [
                'message' => $fields['inquiry_message'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function runId(Request $request): ?int
    {
        $runId = $request->attributes->get('inquiry_run_id');

        return is_int($runId) || ctype_digit((string) $runId) ? (int) $runId : null;
    }
}
