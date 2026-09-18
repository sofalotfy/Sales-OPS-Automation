<?php

namespace App\Triage;

use App\Triage\Handlers\BookingHandler;
use App\Triage\Handlers\DeclineHandler;
use App\Triage\Handlers\EscalateHandler;

/**
 * @deprecated Superseded by the scoring engine (feature 006); retained for reference, not wired into the flow.
 *
 * Strict disposition → handler map (research §5, ai-provider.md).
 *
 * Only the three known Disposition enum cases (FR-005) route to their
 * handlers; a null disposition (unknown/unparseable values are normalized to
 * null by the caller via Disposition::tryFrom before dispatch) lands on
 * EscalateHandler with the preserved context (escalate-by-default — FR-010).
 */
class Dispatcher
{
    public function __construct(
        private readonly DeclineHandler $decline,
        private readonly EscalateHandler $escalate,
        private readonly BookingHandler $booking,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function dispatch(null|Disposition $disposition, array $context): TriageResult
    {
        /** @var TriageResult */
        return match ($disposition) {
            Disposition::Decline => $this->decline->handle($context),
            Disposition::Booking => $this->booking->handle($context),
            default => $this->escalate->handle($context),
        };
    }
}