<?php

namespace App\Triage\Handlers;

use App\Triage\Disposition;
use App\Triage\TriageResult;

/**
 * @deprecated Superseded by the scoring engine (feature 006); retained for reference, not wired into the flow.
 *
 * Escalate disposition — also the escalate-by-default safety net (FR-007,
 * FR-009, FR-010; constitution principle III).
 *
 * v1 scope (Clarifications 2026-09-12): the escalate action is ONLY this
 * decision plus the reply to the inquirer. No persistence, no webhook, no
 * email, no CRM hand-off happens here. The real escalation mechanism is
 * deferred — TODO: define when the escalation requirements are gathered.
 */
final class EscalateHandler implements TriageHandler
{
    public function handle(array $context): TriageResult
    {
        return new TriageResult(
            disposition: Disposition::Escalate,
            reply: 'Thank you. This inquiry has been escalated for a human review — a member of our team will be in touch.',
            reasoning: 'Escalate-by-default: ambiguous, high-value, or system-failure case (FR-007/FR-009/FR-010).',
            context: $context,
        );
    }
}