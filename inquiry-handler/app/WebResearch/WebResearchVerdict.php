<?php

namespace App\WebResearch;

use InvalidArgumentException;

/**
 * The web-research step's outcome for one inquiry (mirrors App\ScopeGate\ScopeVerdict).
 *
 * Exactly one of three outcomes:
 *  - `accept`       → research completed; `research` holds the findings (may
 *                     be empty) and the request passes through enriched;
 *  - `decline`      → the provider short-circuited (e.g. the company is clearly
 *                     not a viable prospect); `refusal` is the visitor-facing
 *                     copy and the flow stops before classification;
 *  - `indeterminate`→ research failed/unavailable; fail open (FR-style degrade),
 *                     request passes through with empty findings.
 *
 * `reason` is always present and is persisted on every screened inquiry record.
 * `refusal` is only ever set on a `decline`.
 */
final class WebResearchVerdict
{
    public const ACCEPT = 'accept';

    public const DECLINE = 'decline';

    public const INDETERMINATE = 'indeterminate';

    private function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly array $research,
        public readonly ?string $refusal = null,
    ) {
        if (! in_array($outcome, [self::ACCEPT, self::DECLINE, self::INDETERMINATE], true)) {
            throw new InvalidArgumentException("Unknown web-research verdict outcome '{$outcome}'.");
        }

        if ($reason === '') {
            throw new InvalidArgumentException('A web-research verdict requires a non-empty reason.');
        }

        if ($outcome === self::DECLINE && ($refusal === null || $refusal === '')) {
            throw new InvalidArgumentException('A declined web-research verdict requires a refusal message.');
        }

        if ($outcome !== self::DECLINE && $refusal !== null) {
            throw new InvalidArgumentException('Refusal copy is only allowed on a declined web-research verdict.');
        }
    }

    /**
     * @param  array<string, mixed>  $research  the agent findings (`{outcome, summary, sources, limitations}`)
     */
    public static function accept(array $research, string $reason = 'Web research completed.'): self
    {
        return new self(self::ACCEPT, $reason, $research, null);
    }

    /**
     * @param  array<string, mixed>  $research  the normalized provider payload (company/person sections)
     */
    public static function decline(array $research, string $reason, string $refusal): self
    {
        return new self(self::DECLINE, $reason, $research, $refusal);
    }

    public static function indeterminate(string $reason, array $research = []): self
    {
        return new self(self::INDETERMINATE, $reason, $research, null);
    }

    public function isDecline(): bool
    {
        return $this->outcome === self::DECLINE;
    }
}