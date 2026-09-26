<?php

namespace App\Http\Controllers;

use App\Services\InquiryTriageService;
use App\Triage\Exceptions\MessageValidationException;
use App\Triage\MessageExtractor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public API surface (contract: contracts/inquiry-web.md).
 *
 * GET / renders the test console (manual testing aid for the flow);
 * POST /inquiry/triage runs the weighted multi-factor classification engine
 * and returns the advisory classification (high|medium|low|disqualify), the
 * normalized 0–100 score, per-factor scores, and a reply. Invalid payloads
 * (message, optional name/email validation) → 422; JSON contract violations →
 * 400; unrecoverable unavailability → 503 (never a fabricated result —
 * FR-014).
 */
class InquiryController extends Controller
{
    public function __construct(
        private readonly MessageExtractor $extractor,
        private readonly InquiryTriageService $service,
    ) {}

    public function show(): View
    {
        return view('inquiry.index');
    }

    public function triage(Request $request): JsonResponse
    {
        // Decode the raw body: a non-object JSON payload (scalar, array, or
        // malformed JSON) is a contract violation → 400, regardless of how the
        // request reached us.
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return response()->json(['detail' => 'A JSON object is required.'], 400);
        }

        // BeginInquiryRun (feature 013) already extracted + validated the body
        // and staged its row; reuse that result so we never re-extract or
        // diverge. When the middleware fell through (invalid payload) the
        // extractor below reproduces the same 400/422 decision.
        try {
            $inquiry = $request->attributes->get('inquiry') ?? $this->extractor->extract($decoded);
        } catch (MessageValidationException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        }

        try {
            $result = $this->service->triage(
                $inquiry,
                $request->attributes->get('scope_check_verdict'),
                $request->attributes->get('web_research_verdict'),
                $request->attributes->get('web_research_criteria', []),
                $request->attributes->get('web_research_audit', []),
                $request->attributes->get('inquiry_run_id'),
            );
        } catch (ConnectionException) {
            return response()->json([
                'detail' => 'Triage service unavailable. Please try again shortly.',
            ], 503);
        }

        return response()->json($result);
    }
}
