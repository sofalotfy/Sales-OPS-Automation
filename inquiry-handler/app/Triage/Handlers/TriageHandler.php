<?php

namespace App\Triage\Handlers;

use App\Triage\TriageResult;

/**
 * @deprecated Superseded by the scoring engine (feature 006); retained for reference, not wired into the flow.
 *
 * A disposition handler takes the preserved triage context (normalized inquiry
 * + retrieved grounding) and produces the uniform TriageResult. The five-line
 * contract for every handler: three of these exist, one per disposition.
 */
interface TriageHandler
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(array $context): TriageResult;
}