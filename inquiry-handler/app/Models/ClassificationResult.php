<?php

namespace App\Models;

use App\Enums\Classification;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only per-inquiry classification log (FR-009, research R10).
 *
 * One row per classified inquiry capturing the full run: the normalized
 * message + the seven-form contact fields, the retrieved grounding, every
 * contributing factor's score/weight/reasoning, dropped factors, the final
 * score, and the classification. Rows are never updated or deleted (audit
 * immutability); the flow only ever calls `create()`.
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
        ];
    }
}
