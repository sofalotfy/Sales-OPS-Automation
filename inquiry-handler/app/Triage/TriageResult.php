<?php

namespace App\Triage;

/**
 * @deprecated Superseded by the scoring engine (feature 006); retained for reference, not wired into the flow.
 *
 * Uniform outcome every handler resolves to (data model: triage_outcome).
 * Context carries the normalized inquiry plus the retrieved grounding and is
 * preserved on all dispositions (never dropped on escalation).
 */
final class TriageResult
{
    public function __construct(
        public readonly Disposition $disposition,
        public readonly string $reply,
        public readonly string $reasoning,
        public readonly array $context,
    ) {
    }

    /** Serialization shape for POST /inquiry/triage (contracts/inquiry-web.md). */
    public function toArray(): array
    {
        return [
            'disposition' => $this->disposition->value,
            'reply' => $this->reply,
            'reasoning' => $this->reasoning,
            'context' => $this->context,
        ];
    }
}