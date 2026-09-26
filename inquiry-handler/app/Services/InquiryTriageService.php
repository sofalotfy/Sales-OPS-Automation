<?php

namespace App\Services;

use App\Enums\Classification;
use App\Enums\InquiryRunStatus;
use App\Models\ClassificationResult;
use App\ScopeGate\ScopeVerdict;
use App\Scoring\ClassificationOutcome;
use App\Scoring\ScoringEngine;
use App\Triage\SystemPrompt;
use App\WebResearch\WebResearchVerdict;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates one classification pass (feature 006): ScoringEngine (weighted
 * multi-factor) → persist the run → visitor reply.
 *
 * Context is always preserved so the degraded/low path carries the full
 * normalized inquiry + the fixed system prompt — nothing is silently dropped.
 * Classification completes even when a helper fails (FR-007/FR-008): a failed
 * classification-log write is logged but never blocks the 200 response.
 *
 * RAG context retrieval is no longer used; the only context each inquiry is
 * judged against is the fixed classification system prompt (App\Triage\SystemPrompt),
 * which is persisted verbatim (`system_prompt`) for audit. The `retrieved_context`
 * column is retained (empty) in case retrieval is re-enabled later.
 *
 * Since feature 013 the sync run's row is opened earlier in the chain
 * (BeginInquiryRun) and the middlewares already landed their stage columns
 * progressively; when a run id is provided, `triage()` marks the run `scoring`
 * before classification and completes it `succeeded` with the result fields.
 * Without a run id it falls back to the pre-feature single create-at-completion.
 */
class InquiryTriageService
{
    public function __construct(
        private readonly ScoringEngine $engine,
        private readonly SystemPrompt $systemPrompt,
        private readonly InquiryRunService $runs,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array<string, mixed>  $webResearchCriteria  the criteria that were sent to the web-research provider
     * @return array<string, mixed> the triage response (contracts/inquiry-web.md)
     */
    public function triage(
        array $inquiry,
        ?ScopeVerdict $scopeVerdict = null,
        ?WebResearchVerdict $webResearchVerdict = null,
        array $webResearchCriteria = [],
        array $webResearchAudit = [],
        ?int $runId = null,
    ): array {
        $context = $this->context($inquiry);

        $context['system_prompt'] = $this->systemPrompt->content();

        if ($scopeVerdict !== null) {
            $context['scope_check'] = [
                'outcome' => $scopeVerdict->outcome,
                'reason' => $scopeVerdict->reason,
            ];
        }

        if ($webResearchVerdict !== null) {
            $context['web_research'] = [
                'outcome' => $webResearchVerdict->outcome,
                'reason' => $webResearchVerdict->reason,
                'criteria' => $webResearchCriteria,
                'findings' => $webResearchVerdict->research,
            ];
        }

        if ($runId !== null) {
            $this->runs->markStatus($runId, InquiryRunStatus::Scoring);
        }

        $outcome = $this->engine->classify($inquiry, $context);

        $this->persist($inquiry, $context['retrieved_context'], $outcome, $scopeVerdict, $webResearchVerdict, $webResearchCriteria, $webResearchAudit, $runId);

        return $this->respond($outcome, $context);
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @return array<string, mixed>
     */
    private function context(array $inquiry): array
    {
        return [
            'inquiry' => [
                'first_name' => $inquiry['first_name'],
                'last_name' => $inquiry['last_name'],
                'email' => $inquiry['email'],
                'phone_number' => $inquiry['phone_number'] ?? null,
                'company_name' => $inquiry['company_name'] ?? null,
                'country_region' => $inquiry['country_region'] ?? null,
                'message' => $inquiry['message'],
            ],
            'retrieved_context' => ['result_count' => 0, 'results' => []],
        ];
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array{result_count: int, results: array<mixed>}  $retrievedContext
     * @param  array<string, mixed>  $webResearchCriteria
     */
    private function persist(
        array $inquiry,
        array $retrievedContext,
        ClassificationOutcome $outcome,
        ?ScopeVerdict $scopeVerdict = null,
        ?WebResearchVerdict $webResearchVerdict = null,
        array $webResearchCriteria = [],
        array $webResearchAudit = [],
        ?int $runId = null,
    ): void {
        try {
            if ($runId !== null) {
                // The staged row already carries the payload + any research and
                // scope columns the middlewares landed; complete it with the
                // result fields only (written once, at completion).
                $this->runs->markSucceeded($runId, [
                    'retrieved_context' => $retrievedContext,
                    'factor_scores' => $outcome->factorScoresArray(),
                    'dropped_factors' => $outcome->droppedFactors,
                    'final_score' => $outcome->score,
                    'classification' => $outcome->classification,
                    'reasoning' => $outcome->reasoning,
                    'system_prompt' => $this->systemPrompt->content(),
                    'refusal' => null,
                ]);

                return;
            }

            ClassificationResult::create([
                'inquiry_message' => $inquiry['message'],
                'first_name' => $inquiry['first_name'],
                'last_name' => $inquiry['last_name'],
                'email' => $inquiry['email'],
                'phone_number' => $inquiry['phone_number'] ?? null,
                'company_name' => $inquiry['company_name'] ?? null,
                'country_region' => $inquiry['country_region'] ?? null,
                'retrieved_context' => $retrievedContext,
                'factor_scores' => $outcome->factorScoresArray(),
                'dropped_factors' => $outcome->droppedFactors,
                'final_score' => $outcome->score,
                'classification' => $outcome->classification,
                'reasoning' => $outcome->reasoning,
                'scope_check_outcome' => $scopeVerdict?->outcome,
                'scope_check_reason' => $scopeVerdict?->reason,
                'web_research_outcome' => $webResearchVerdict?->outcome,
                'web_research_reason' => $webResearchVerdict?->reason,
                'web_research' => $webResearchVerdict === null ? null : [
                    'criteria' => $webResearchCriteria,
                    'findings' => $webResearchVerdict->research,
                    'audit' => $webResearchAudit,
                ],
                'system_prompt' => $this->systemPrompt->content(),
                'refusal' => null,
                'status' => InquiryRunStatus::Succeeded,
            ]);
        } catch (Throwable $e) {
            // The inbound response must still be delivered (research R3d).
            Log::error('Failed to persist classification result.', [
                'message' => $inquiry['message'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function respond(ClassificationOutcome $outcome, array $context): array
    {
        return [
            'classification' => $outcome->classification->value,
            'score' => $outcome->score,
            'factor_scores' => $outcome->factorScoresArray(),
            'dropped_factors' => $outcome->droppedFactors,
            'reply' => $this->replyFor($outcome->classification),
            'reasoning' => $outcome->reasoning,
            'context' => $context,
        ];
    }

    /**
     * Visitor-facing placeholder copy per classification (research R11).
     * The `high` reply substitutes the configured booking link only when that
     * value is a valid URL; otherwise a generic placeholder is used (SC guard
     * on services.booking_url, same rule the legacy booking handler applied).
     */
    private function replyFor(Classification $classification): string
    {
        $template = (string) (config('scoring.replies.'.$classification->value) ?? '');

        if ($template === '') {
            return 'Thank you for your inquiry.';
        }

        if ($classification !== Classification::High) {
            return $template;
        }

        $url = (string) config('services.booking_url');

        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return str_replace('{booking_url}', $url, $template);
        }

        return 'Thanks for reaching out. A member of our team will follow up with the details.';
    }
}
