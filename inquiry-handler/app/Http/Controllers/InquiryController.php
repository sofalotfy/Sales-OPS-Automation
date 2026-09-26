<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTriageJob;
use App\Services\InquiryRunService;
use App\Triage\Exceptions\MessageValidationException;
use App\Triage\MessageExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * The single public inquiry surface (contract: contracts/inquiry-web.md).
 *
 * GET / renders the test console (manual testing aid for the flow).
 *
 * POST /inquiry/triage — authenticates the caller through the shared
 * `X-CRM-Key` credential (the `crm.key` middleware), validates the payload,
 * opens the run row and dispatches it to the Redis queue, answering
 * `202 {"inquiry_id": N, "status": "queued"}` immediately. The
 * inquiry-worker then runs the weighted multi-factor classification engine
 * (web research → scope gate → scoring) and advances the row's status, so the
 * caller polls GET /inquiry/{id} for the advisory classification
 * (high|medium|low|disqualify), the normalized 0–100 score, per-factor scores,
 * and a reply. Invalid payloads (message, optional name/email validation) →
 * 422; JSON contract violations → 400; a re-submitted (campaign_id, lead_id)
 * pair → 409 without re-running; queue unavailable → 503 (never a fabricated
 * result — FR-014).
 *
 * GET /inquiry/{id} — the poll payload for one run. Unknown id → 404.
 */
class InquiryController extends Controller
{
    /**
     * Campaign used when a caller (the test console, mostly) sends no CRM
     * identifiers. The run row is still idempotent per (campaign_id, lead_id),
     * and a synthesised lead id keeps every console submission distinct.
     */
    private const DEFAULT_CAMPAIGN_ID = 'test-console';

    public function __construct(
        private readonly MessageExtractor $extractor,
        private readonly InquiryRunService $runs,
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

        try {
            $inquiry = $this->extractor->extract($decoded);
        } catch (MessageValidationException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        }

        $campaignId = trim((string) ($decoded['campaign_id'] ?? '')) ?: self::DEFAULT_CAMPAIGN_ID;
        $leadId = trim((string) ($decoded['lead_id'] ?? '')) ?: 'console-'.Str::uuid()->toString();

        try {
            $result = $this->runs->enqueue($campaignId, $leadId, $inquiry);
        } catch (Throwable $e) {
            Log::error('Failed to enqueue inquiry.', [
                'campaign_id' => $campaignId,
                'lead_id' => $leadId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['detail' => 'Queue unavailable. Please retry in a moment.'], 503);
        }

        $record = $result['record'];

        $payload = [
            'inquiry_id' => (int) $record->id,
            'status' => $result['created'] ? 'queued' : 'existing',
            'campaign_id' => $campaignId,
            'lead_id' => $leadId,
        ];

        if (! $result['created']) {
            // Already accepted: idempotent ack, no re-run, no double spend.
            return response()->json($payload, 409);
        }

        try {
            ProcessTriageJob::dispatch($record->id);
        } catch (Throwable $e) {
            // Nothing was enqueued — never leave a row that promises
            // processing (FR: nothing silently dropped).
            $this->runs->delete((int) $record->id);

            Log::error('Failed to dispatch inquiry run.', [
                'inquiry_id' => $record->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['detail' => 'Queue unavailable. Please retry in a moment.'], 503);
        }

        return response()->json($payload, 202);
    }

    public function poll(int $id): JsonResponse
    {
        $record = $this->runs->findById($id);

        if ($record === null) {
            return response()->json(['detail' => 'Inquiry not found.'], 404);
        }

        return response()->json($this->runs->pollPayload($record));
    }
}
