<?php

namespace App\Triage\Handlers;

use App\Triage\Disposition;
use App\Triage\TriageResult;

/**
 * @deprecated Superseded by the scoring engine (feature 006); retained for reference, not wired into the flow.
 *
 * Booking disposition: meet-ready prospects receive the configured booking
 * link (FR-008). If no usable link is configured the inquiry MUST escalate,
 * never present a broken link (FR-009).
 */
final class BookingHandler implements TriageHandler
{
    public function __construct(private readonly EscalateHandler $escalate)
    {
    }

    public function handle(array $context): TriageResult
    {
        $url = (string) config('services.booking_url');

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || $url === '') {
            return $this->escalate->handle($context + [
                'fallback_reason' => 'booking link not configured',
            ]);
        }

        return new TriageResult(
            disposition: Disposition::Booking,
            reply: "You are in the right place — let's get that booked. Pick a time here: {$url}",
            reasoning: 'Qualified, meeting-ready sales inquiry (FR-008).',
            context: $context + ['booking_url' => $url],
        );
    }
}