<?php

namespace App\Http\Middleware;

use App\Enums\InquiryRunStatus;
use App\Models\ClassificationResult;
use App\Services\InquiryRunService;
use App\Triage\Exceptions\MessageValidationException;
use App\Triage\MessageExtractor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * First stage of the synchronous POST /inquiry/triage chain (feature 013):
 * opens the run's row as soon as the payload is valid, before any research or
 * scope work happens.
 *
 * The row is created with the payload fields and `status = processing`; the
 * row's id rides on the request (`inquiry_run_id`) so the web-research and
 * scope-gate middlewares and the triage service UPDATE it progressively
 * (stage data + status) instead of each writing a fresh row. When the create
 * fails the request still completes — the middlewares fall back to their
 * existing create-on-decline sites and the triage service to its usual
 * create-at-completion, so the inbound response is never held hostage (FR-007).
 *
 * Invalid payloads (non-object JSON or a MessageValidationException) fall
 * through untouched so the controller emits the existing 400/422 unchanged.
 */
class BeginInquiryRun
{
    public function __construct(
        private readonly MessageExtractor $extractor,
        private readonly InquiryRunService $runs,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $next($request);
        }

        try {
            $inquiry = $this->extractor->extract($decoded);
        } catch (MessageValidationException) {
            return $next($request);
        }

        $request->attributes->set('inquiry', $inquiry);

        try {
            $record = ClassificationResult::create(
                $this->runs->payloadFieldsFor($inquiry) + [
                    'status' => InquiryRunStatus::Processing,
                ],
            );
            $request->attributes->set('inquiry_run_id', (int) $record->id);
        } catch (Throwable $e) {
            Log::error('Failed to open a synchronous inquiry run.', [
                'error' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }
}
