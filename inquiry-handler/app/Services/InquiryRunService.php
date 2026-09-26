<?php

namespace App\Services;

use App\Enums\Classification;
use App\Enums\InquiryRunStatus;
use App\Models\ClassificationResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Single owner of a run's lifecycle on the classification log (feature 013,
 * data-model.md): creation, stage transitions, and the poll envelope.
 *
 * A run is written progressively: the row is created with payload fields only
 * (`queued` for async enqueues, `processing` for sync requests), each stage
 * then lands its own columns (web_research_*, scope_check_*, result fields)
 * as it completes, and the status advances per stage. Result fields
 * (`final_score`, `classification`, `factor_scores`, ...) are still written
 * exactly once, at completion — the audit invariant is preserved.
 *
 * The same service backs both paths: POST /inquiry/enqueue + ProcessTriageJob
 * (async) and the existing POST /inquiry/triage chain (sync, where
 * BeginInquiryRun creates the row early and the middlewares + triage service
 * call back into this service to advance it).
 */
class InquiryRunService
{
    /**
     * Find the row for a CRM pair, else create it `queued` with the payload
     * fields only (the pipeline writes the rest later).
     *
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @return array{created: bool, record: ClassificationResult}
     *
     * @throws Throwable when the store is unwritable and no record could be reconciled
     */
    public function enqueue(string $campaignId, string $leadId, array $inquiry): array
    {
        $existing = ClassificationResult::query()
            ->where('campaign_id', $campaignId)
            ->where('lead_id', $leadId)
            ->first();

        if ($existing !== null) {
            return ['created' => false, 'record' => $existing];
        }

        try {
            return [
                'created' => true,
                'record' => ClassificationResult::create(
                    $this->payloadFieldsFor($inquiry) + [
                        'campaign_id' => $campaignId,
                        'lead_id' => $leadId,
                        'status' => InquiryRunStatus::Queued,
                    ],
                ),
            ];
        } catch (UniqueConstraintViolationException) {
            // Two enqueues raced; the unique (campaign_id, lead_id) index let
            // exactly one row land. Reconcile to the winner and report existing.
            $record = ClassificationResult::query()
                ->where('campaign_id', $campaignId)
                ->where('lead_id', $leadId)
                ->firstOrFail();

            return ['created' => false, 'record' => $record];
        }
    }

    public function findById(int $id): ?ClassificationResult
    {
        return ClassificationResult::query()->find($id);
    }

    /**
     * Rebuild the canonical inquiry array from the row's payload columns
     * (the async job reads these back instead of re-storing the raw body).
     *
     * @return array{first_name: ?string, last_name: ?string, email: ?string, phone_number: ?string, company_name: ?string, country_region: ?string, message: ?string}
     */
    public function inquiryFromRecord(ClassificationResult $record): array
    {
        return [
            'first_name' => $record->first_name,
            'last_name' => $record->last_name,
            'email' => $record->email,
            'phone_number' => $record->phone_number,
            'company_name' => $record->company_name,
            'country_region' => $record->country_region,
            'message' => $record->inquiry_message,
        ];
    }

    public function markStatus(int $id, InquiryRunStatus $status): void
    {
        $this->update($id, ['status' => $status]);
    }

    /**
     * Merge arbitrary lifecycle/result columns into the run (no-op when the
     * row is gone).
     *
     * @param  array<string, mixed>  $fields
     */
    public function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        // The query-builder update bypasses model casts, so normalize backed
        // enums (status, classification) to their string values before writing.
        $fields = array_map(
            static fn (mixed $value) => $value instanceof \BackedEnum ? $value->value : $value,
            $fields,
        );

        ClassificationResult::query()->whereKey($id)->update($fields);
    }

    /**
     * Complete a run: write the completion fields and land `succeeded`
     * (covers both real results and decline-shape completions with `refusal`).
     *
     * @param  array<string, mixed>  $fields
     */
    public function markSucceeded(int $id, array $fields): void
    {
        $this->update($id, $fields + ['status' => InquiryRunStatus::Succeeded]);
    }

    public function markFailed(int $id, string $reason): void
    {
        $this->update($id, ['status' => InquiryRunStatus::Failed, 'error' => $reason]);
    }

    /**
     * Remove a run that must not be processed (e.g. the enqueue endpoint
     * rolled back because dispatching the job failed). No-op when already gone.
     */
    public function delete(int $id): void
    {
        ClassificationResult::query()->whereKey($id)->delete();
    }

    /**
     * The CRM poll payload (contracts/crm-ingest-web.md): status + `result`
     * (the full synchronous triage envelope) only once `succeeded` and the
     * classification has landed (declines carry a disqualify envelope too),
     * otherwise `null` + any `error`.
     *
     * @return array{inquiry_id: int, campaign_id: ?string, lead_id: ?string, status: ?string, result: array<string, mixed>|null, error: ?string}
     */
    public function pollPayload(ClassificationResult $record): array
    {
        return [
            'inquiry_id' => (int) $record->id,
            'campaign_id' => $record->campaign_id,
            'lead_id' => $record->lead_id,
            'status' => $record->status?->value,
            'result' => $record->status === InquiryRunStatus::Succeeded && $record->classification !== null
                ? $this->triageEnvelope($record)
                : null,
            'error' => $record->error,
        ];
    }

    /**
     * The payload-only column set for one inquiry (contact fields + the
     * message), shared by enqueue(), BeginInquiryRun, and the job's fields.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @return array<string, mixed>
     */
    public function payloadFieldsFor(array $inquiry): array
    {
        return [
            'inquiry_message' => $inquiry['message'],
            'first_name' => $inquiry['first_name'],
            'last_name' => $inquiry['last_name'],
            'email' => $inquiry['email'],
            'phone_number' => $inquiry['phone_number'] ?? null,
            'company_name' => $inquiry['company_name'] ?? null,
            'country_region' => $inquiry['country_region'] ?? null,
        ];
    }

    /**
     * Reconstruct the synchronous triage response envelope from a completed
     * row (same shape InquiryTriageService::respond/context produce).
     *
     * @return array<string, mixed>
     */
    private function triageEnvelope(ClassificationResult $record): array
    {
        $context = [
            'inquiry' => $this->inquiryFromRecord($record),
            'system_prompt' => $record->system_prompt ?? '',
            'retrieved_context' => $record->retrieved_context ?? ['result_count' => 0, 'results' => []],
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

        return [
            'classification' => $record->classification?->value,
            'score' => (float) ($record->final_score ?? 0.0),
            'factor_scores' => $record->factor_scores ?? [],
            'dropped_factors' => $record->dropped_factors ?? [],
            'reply' => $record->refusal ?? $this->replyFor($record->classification),
            'reasoning' => $record->reasoning ?? '',
            'context' => $context,
        ];
    }

    /**
     * Visitor-facing placeholder copy per classification (mirror of
     * InquiryTriageService::replyFor, kept here so the async poll envelope
     * reproduces the same reply without a factor/booking dependency).
     */
    private function replyFor(?Classification $classification): string
    {
        if ($classification === null) {
            return '';
        }

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
