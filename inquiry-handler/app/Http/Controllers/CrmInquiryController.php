<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTriageJob;
use App\Services\InquiryRunService;
use App\Triage\Exceptions\MessageValidationException;
use App\Triage\MessageExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * CRM ingest surface (feature 013, US1; contract: contracts/crm-ingest-web.md).
 *
 * POST /inquiry/enqueue — accepts one campaign lead (validation identical to
 * the synchronous contract), acknowledges it with its run id, and dispatches
 * the background ProcessTriageJob. Idempotent on (campaign_id, lead_id):
 * re-submitting an already-accepted pair returns 409 and never re-runs.
 * Nothing is silently dropped: an enqueue/dispatch failure yields 503 and any
 * row that could not be queued is removed.
 *
 * GET /inquiry/{id} — returns the poll payload. Unknown id → 404.
 *
 * Both routes sit behind the shared-key middleware (X-CRM-Key) + a Redis
 * backed per-key rate limiter (throttle:crm).
 */
class CrmInquiryController extends Controller
{
    public function __construct(
        private readonly MessageExtractor $extractor,
        private readonly InquiryRunService $runs,
    ) {}

    public function enqueue(Request $request): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $this->error('A JSON object is required.', 400);
        }

        $validator = Validator::make($decoded, [
            'campaign_id' => ['required', 'string', 'max:255'],
            'lead_id' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        try {
            $inquiry = $this->extractor->extract($decoded);
        } catch (MessageValidationException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $campaignId = trim((string) $decoded['campaign_id']);
        $leadId = trim((string) $decoded['lead_id']);

        try {
            $result = $this->runs->enqueue($campaignId, $leadId, $inquiry);
        } catch (Throwable $e) {
            Log::error('Failed to enqueue CRM inquiry.', [
                'campaign_id' => $campaignId,
                'lead_id' => $leadId,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Queue unavailable. Please retry in a moment.', 503);
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

            Log::error('Failed to dispatch CRM inquiry run.', [
                'inquiry_id' => $record->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Queue unavailable. Please retry in a moment.', 503);
        }

        return response()->json($payload, 202);
    }

    public function poll(int $id): JsonResponse
    {
        $record = $this->runs->findById($id);

        if ($record === null) {
            return $this->error('Inquiry not found.', 404);
        }

        return response()->json($this->runs->pollPayload($record));
    }

    private function error(string $detail, int $status): JsonResponse
    {
        return response()->json(['detail' => $detail], $status);
    }
}
