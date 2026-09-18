<?php

namespace App\Triage\Handlers;

use App\Triage\Disposition;
use App\Triage\TriageResult;

/**
 * @deprecated Superseded by the scoring engine (feature 006); retained for reference, not wired into the flow.
 *
 * Decline disposition: courteous out-of-scope / non-sales reply (FR-006/SC-4).
 * No escalation, no booking link.
 */
final class DeclineHandler implements TriageHandler
{
    public function handle(array $context): TriageResult
    {
        return new TriageResult(
            disposition: Disposition::Decline,
            reply: 'Thank you for reaching out. This request is outside the scope of the services we offer, so we are unable to help further on this topic.',
            reasoning: 'Out-of-scope or non-sales inquiry (FR-006).',
            context: $context,
        );
    }
}