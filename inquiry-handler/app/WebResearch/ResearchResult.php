<?php

namespace App\WebResearch;

/**
 * Result of one AI research agent run (feature 010).
 *
 * Immutable value object: the run outcome, the visitor-safe summary (or an
 * explicit not-found/ambiguous/failure statement), the sources that were
 * actually fetched and cited, any stated limitations, whether the profile
 * is best-effort, and a lightweight **candidate audit** snapshot.
 *
 * The audit (`$audit`) records how many candidates were gathered, which ones
 * survived the AI filter, and which were rejected, so operators can verify
 * the filter is neither too greedy nor too lax. It is persisted in the
 * `web_research.audit` column but never emitted in the public triage
 * response unless explicitly surfaced (admin dashboard only).
 *
 * `toFindings()` produces the shape carried forward as
 * `web_research.findings` in the response and the persisted record.
 */
final class ResearchResult
{
    /**
     * @param  array<int, array{title: string, url: string}>  $sources
     * @param  array<string, mixed>  $audit  candidate-filter audit snapshot
     */
    public function __construct(
        public readonly ResearchOutcome $outcome,
        public readonly string $summary,
        public readonly array $sources = [],
        public readonly ?string $limitations = null,
        public readonly bool $uncertain = false,
        public readonly array $audit = [],
    ) {}

    public static function indeterminate(string $reason, array $audit = []): self
    {
        return new self(ResearchOutcome::Indeterminate, $reason, audit: $audit);
    }

    public static function notFound(string $summary, array $audit = []): self
    {
        return new self(ResearchOutcome::NotFound, $summary, audit: $audit);
    }

    public static function ambiguous(string $summary, array $audit = []): self
    {
        return new self(ResearchOutcome::Ambiguous, $summary, audit: $audit);
    }

    /**
     * The carried-forward findings shape:
     * `{outcome, summary, sources, limitations, uncertain}`.
     *
     * @return array{outcome: string, summary: string, sources: array<int, array{title: string, url: string}>, limitations: string|null, uncertain: bool}
     */
    public function toFindings(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'summary' => $this->summary,
            'sources' => $this->sources,
            'limitations' => $this->limitations,
            'uncertain' => $this->uncertain,
        ];
    }
}
