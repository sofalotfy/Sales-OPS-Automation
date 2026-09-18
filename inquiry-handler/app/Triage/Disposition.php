<?php

namespace App\Triage;

/**
 * @deprecated Superseded by the scoring engine (feature 006); retained for reference, not wired into the flow.
 *
 * The only three dispositions the triage system can produce (FR-005).
 */
enum Disposition: string
{
    case Decline = 'decline';
    case Escalate = 'escalate';
    case Booking = 'booking';
}