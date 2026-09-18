<?php

namespace App\Triage\Exceptions;

/**
 * @deprecated Superseded by the scoring engine (feature 006); retained for reference, not wired into the flow.
 *
 * Raised by MessageExtractor when the inbound payload fails validation
 * (FR-002): controller maps it to a 422 with a clear detail message.
 */
final class MessageValidationException extends \RuntimeException
{
}