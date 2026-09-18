<?php

namespace App\ScopeGate;

use InvalidArgumentException;

/**
 * The scope gate's decision on one inquiry (data-model.md / research R2).
 *
 * Exactly one of three outcomes: `accept` (in scope), `decline` (out of
 * scope), or `indeterminate` (grounding/AI unavailable — fail open). The
 * `reason` is always present and is stored on every screened inquiry record
 * (FR-012). `refusal` is the visitor-facing copy and is only ever set on a
 * `decline`; on accept/indeterminate it is null.
 */
final class ScopeVerdict
{
    public const ACCEPT = 'accept';

    public const DECLINE = 'decline';

    public const INDETERMINATE = 'indeterminate';

    private function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?string $refusal,
    ) {
        if (! in_array($outcome, [self::ACCEPT, self::DECLINE, self::INDETERMINATE], true)) {
            throw new InvalidArgumentException("Unknown scope verdict outcome '{$outcome}'.");
        }

        if ($reason === '') {
            throw new InvalidArgumentException('A scope verdict requires a non-empty reason.');
        }

        if ($outcome === self::DECLINE && ($refusal === null || $refusal === '')) {
            throw new InvalidArgumentException('A declined scope verdict requires a refusal message.');
        }

        if ($outcome !== self::DECLINE && $refusal !== null) {
            throw new InvalidArgumentException('Refusal copy is only allowed on a declined scope verdict.');
        }
    }

    public static function accept(string $reason): self
    {
        return new self(self::ACCEPT, $reason, null);
    }

    public static function decline(string $reason, string $refusal): self
    {
        return new self(self::DECLINE, $reason, $refusal);
    }

    public static function indeterminate(string $reason): self
    {
        return new self(self::INDETERMINATE, $reason, null);
    }

    public function isDecline(): bool
    {
        return $this->outcome === self::DECLINE;
    }
}
