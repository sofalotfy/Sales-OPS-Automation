<?php

namespace App\Http\Middleware;

use App\Enums\Classification;
use App\Models\ClassificationResult;
use App\ScopeGate\ScopeCheckService;
use App\ScopeGate\ScopeVerdict;
use App\Triage\Exceptions\MessageValidationException;
use App\Triage\MessageExtractor;
use App\Triage\SystemPrompt;
use App\WebResearch\WebResearchVerdict;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Pre-classification scope gate (feature 007, research R1/R2/R6).
 *
 * Screens the public POST /inquiry/triage path only: retrieve the company's
 * knowledge documents, ask the AI whether the inquiry is in scope, then:
 *  - accept → pass the request through to the classification flow unchanged
 *    (the verdict rides on the request attributes so the row + response carry
 *    the scope marker — FR-012);
 *  - decline → stop before classification (SC-001), persist the refusal record
 *    (FR-008) and return a same-shape refusal response with the reason (FR-006);
 *  - indeterminate → fail open: pass through unchanged (US3, FR-007).
 *
 * The gate never screens an invalid payload: a non-object JSON body or an
 * extraction/validation failure falls through so the controller emits the
 * existing 400/422 unchanged (spec Edge Cases). When scope_gate.enabled is
 * false the gate is fully bypassed and no scope_check marker is produced.
 */
class ScopeGateMiddleware
{
    public function __construct(
        private readonly MessageExtractor $extractor,
        private readonly ScopeCheckService $scopeCheck,
        private readonly SystemPrompt $systemPrompt,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('scope_gate.enabled')) {
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

        $result = $this->scopeCheck->check($inquiry);
        $verdict = $result['verdict'];

        $request->attributes->set('scope_check_verdict', $verdict);

        if ($verdict->isDecline()) {
            return $this->declined($request, $inquiry, $result);
        }

        return $next($request);
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array{verdict: ScopeVerdict, retrieved: array{result_count: int, results: array<mixed>}|null}  $result
     */
    private function declined(Request $request, array $inquiry, array $result): JsonResponse
    {
        $verdict = $result['verdict'];
        $retrieved = $result['retrieved'] ?? ['result_count' => 0, 'results' => []];
        $reason = $verdict->reason;
        $refusal = $verdict->refusal ?? $reason;

        // The web-research step runs before this gate, so a scope decline can
        // still record whatever AI research findings were already gathered.
        $webResearchVerdict = $request->attributes->get('web_research_verdict');
        $webResearchCriteria = $request->attributes->get('web_research_criteria', []);
        $webResearchAudit = $request->attributes->get('web_research_audit', null);

        try {
            ClassificationResult::create([
                'inquiry_message' => $inquiry['message'],
                'first_name' => $inquiry['first_name'],
                'last_name' => $inquiry['last_name'],
                'email' => $inquiry['email'],
                'phone_number' => $inquiry['phone_number'] ?? null,
                'company_name' => $inquiry['company_name'] ?? null,
                'country_region' => $inquiry['country_region'] ?? null,
                'retrieved_context' => $retrieved,
                'factor_scores' => [],
                'dropped_factors' => [],
                'final_score' => 0.0,
                'classification' => Classification::Disqualify,
                'reasoning' => $reason,
                'scope_check_outcome' => ScopeVerdict::DECLINE,
                'scope_check_reason' => $reason,
                'web_research_outcome' => $webResearchVerdict instanceof WebResearchVerdict ? $webResearchVerdict->outcome : null,
                'web_research_reason' => $webResearchVerdict instanceof WebResearchVerdict ? $webResearchVerdict->reason : null,
                'web_research' => $webResearchVerdict instanceof WebResearchVerdict ? [
                    'criteria' => $webResearchCriteria,
                    'findings' => $webResearchVerdict->research,
                    'audit' => $webResearchAudit,
                ] : null,
                'system_prompt' => $this->systemPrompt->content(),
                'refusal' => $refusal,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to persist declined scope-gate record.', [
                'message' => $inquiry['message'],
                'error' => $e->getMessage(),
            ]);
        }

        $context = [
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
            'retrieved_context' => $retrieved,
            'scope_check' => [
                'outcome' => ScopeVerdict::DECLINE,
                'reason' => $reason,
            ],
        ];

        if ($webResearchVerdict instanceof WebResearchVerdict) {
            $context['web_research'] = [
                'outcome' => $webResearchVerdict->outcome,
                'reason' => $webResearchVerdict->reason,
                'criteria' => $webResearchCriteria,
                'findings' => $webResearchVerdict->research,
            ];
        }

        return response()->json([
            'classification' => Classification::Disqualify->value,
            'score' => 0.0,
            'factor_scores' => [],
            'dropped_factors' => [],
            'reply' => $refusal,
            'reasoning' => $reason,
            'context' => $context,
        ]);
    }
}
