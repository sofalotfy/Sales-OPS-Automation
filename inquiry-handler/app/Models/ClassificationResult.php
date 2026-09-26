<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\InquiryRunStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-inquiry classification log (FR-009, research R15), now lifecycle-aware
 * (feature 013, data-model.md).
 *
 * One row per inquiry capturing the full run: the normalized message + the
 * seven-form contact fields, the retrieved grounding, every contributing
 * factor's score/weight/reasoning, dropped factors, the final score, and the
 * classification. Result fields (`factor_scores`, `reasoning`, classification,
 * etc.) are written exactly once at completion (the audit invariant); only the
 * lifecycle columns `status`/`error` transition.
 *
 * For synchronous (console) runs the row is created and completed in one pass,
 * so it is written with `status = succeeded` directly. For CRM runs (feature
 * 013) the row is created `queued` by POST /inquiry/enqueue, moves to
 * `processing` when a worker picks it up, and lands in `succeeded` or `failed`.
 * `campaign_id` + `lead_id` carry the CRM's idempotency key (unique pair;
 * Postgres NULLs are distinct, so sync rows never collide).
 *
 * Since feature 007 the row also records the scope gate's outcome for every
 * screened inquiry: `scope_check_outcome` (accept|decline|indeterminate), the
 * scope reasoning, and for declines the visitor-facing `refusal` (FR-012).
 *
 * Since the web-research step the row also records that step's outcome for
 * every enriched inquiry: `web_research_outcome`, the research reason, and the
 * `web_research` JSON. Since feature 010 that JSON carries the contact-safe
 * lookup criteria plus the AI research agent's findings
 * (`{outcome, summary, sources, limitations}`); raw candidate results are not
 * retained.
 *
 * Since RAG context retrieval was replaced by a fixed system prompt, the row
 * also records the `system_prompt` (the company/scope context each inquiry was
 * judged against).
 */
class ClassificationResult extends Model
{
    protected $table = 'classification_results';

    protected $fillable = [
        'inquiry_message',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'company_name',
        'country_region',
        'retrieved_context',
        'factor_scores',
        'dropped_factors',
        'final_score',
        'classification',
        'reasoning',
        'scope_check_outcome',
        'scope_check_reason',
        'refusal',
        'web_research_outcome',
        'web_research_reason',
        'web_research',
        'system_prompt',
        'campaign_id',
        'lead_id',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'retrieved_context' => 'array',
            'factor_scores' => 'array',
            'dropped_factors' => 'array',
            'web_research' => 'array',
            'final_score' => 'float',
            'classification' => Classification::class,
            'status' => InquiryRunStatus::class,
        ];
    }
}
