<?php

namespace App\Scoring;

use App\Services\AiCallingService;

/**
 * The first registered factor (feature 009): how big is the inquirer's company.
 *
 * The size estimate is computed by an AI call whose input is the web-research
 * findings (`context.web_research.findings`) produced by the research agent
 * (feature 010) — the grounded summary plus the fetched/cited sources.
 *
 * Structure and rules (FR-001..FR-006):
 *  - Registered first in the factor registry so it leads the factor breakdown.
 *  - A deterministic 0–100 score derived from the findings' size signals; the
 *    scoring engine clamps defensively, this factor only ever declares 0–100.
 *  - NEVER fabricates: blank company name, no findings, a `not_found` /
 *    `ambiguous` research outcome, an unavailable/invalid AI estimate all
 *    return score 0 with an honest reasoning string (FR-003/FR-005).
 *  - Uses ONLY `company_name` (+ optional `country_region`) and the public
 *    findings; first/last/email/phone are never sent to the AI (FR-004).
 *  - Weight comes from the factor-settings store via FactorScorer (FR-006).
 */
final class CompanySizeFactor implements ScoreFactor
{
    public function __construct(
        private readonly AiCallingService $ai,
    ) {
    }

    public function name(): string
    {
        return 'company_size';
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array<string, mixed>  $context
     */
    public function score(array $inquiry, array $context): FactorVerdict
    {
        $company = $this->companyName($inquiry);

        if ($company === null) {
            return new FactorVerdict(0, 'Company size could not be estimated: no company name was provided.');
        }

        $findings = $this->findings($context);

        if ($findings === null) {
            return new FactorVerdict(0, 'Company size could not be estimated: no web-research findings were available.');
        }

        $outcome = (string) ($findings['outcome'] ?? 'completed');

        if ($outcome === 'not_found') {
            return new FactorVerdict(0, 'Company size could not be estimated: no public information about the company was found.');
        }

        if ($outcome === 'ambiguous') {
            return new FactorVerdict(0, 'Company size could not be estimated: the company name matched several distinct entities.');
        }

        $degraded = $this->degradedVerdict($findings);

        if ($degraded !== null) {
            return $degraded;
        }

        $decoded = $this->ai->complete(
            $this->systemPrompt(),
            $this->userPrompt($company, $inquiry['country_region'] ?? null, $findings),
        );

        if (! is_array($decoded)) {
            return new FactorVerdict(0, 'Company size could not be estimated: the AI size estimate was unavailable.');
        }

        $score = $decoded['score'] ?? null;

        if (! is_numeric($score)) {
            return new FactorVerdict(0, 'Company size could not be estimated: the AI returned an invalid score.');
        }

        $score = (int) $score;

        if ($score < 0 || $score > 100) {
            return new FactorVerdict(0, 'Company size could not be estimated: the AI returned a score outside 0-100.');
        }

        $reasoning = is_string($decoded['reasoning'] ?? null) ? trim($decoded['reasoning']) : '';

        if ($reasoning === '') {
            $reasoning = 'Company size estimated at '.$score.'/100 from the research findings.';
        }

        $meta = [];

        if (isset($decoded['size_band']) && is_string($decoded['size_band'])) {
            $meta['size_band'] = $decoded['size_band'];
        }

        if (isset($decoded['employee_count']) && is_int($decoded['employee_count'])) {
            $meta['employee_count'] = $decoded['employee_count'];
        }

        return new FactorVerdict($score, $reasoning, $meta);
    }

    /**
     * Guard against burning an AI call on findings that carry no usable
     * content to estimate from (visitor-safe no-signal cases).
     *
     * @param  array<string, mixed>  $findings
     */
    private function degradedVerdict(array $findings): ?FactorVerdict
    {
        $summary = trim((string) ($findings['summary'] ?? ''));

        if ($summary === '' && ($findings['sources'] ?? []) === []) {
            return new FactorVerdict(0, 'Company size could not be estimated: the research findings contained no usable content.');
        }

        return null;
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     */
    private function companyName(array $inquiry): ?string
    {
        $name = $inquiry['company_name'] ?? null;

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function findings(array $context): ?array
    {
        $findings = $context['web_research']['findings'] ?? null;

        return is_array($findings) ? $findings : null;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a company-size estimator for a B2B sales triage engine.

Given a target company and the public web-research findings about it, estimate
the company's size as one integer score from 0 to 100.

Rubric (approximate; apply judgment from the evidence):
- 0     -> the research gives no reliable company-size signal (do NOT
           fabricate; return 0 and explain why)
- 1-25  -> very small / micro (e.g. freelancer, local shop, fewer than 10 employees)
- 26-50 -> small (e.g. 10-200 employees, local office)
- 51-75 -> mid-size (e.g. 200-1,000 employees, several offices)
- 76-90 -> large (e.g. 1,000-10,000 employees, multi-country presence)
- 91-100-> very large / market-leading global enterprise (e.g. 10,000+ employees)

Rules:
1. Base the estimate ONLY on evidence present in the findings (employee counts,
   revenue, offices, funding rounds, brand reach, sector leadership).
2. If no company-size signal can be extracted, return score 0.
3. NEVER invent revenue, headcount, or employee numbers.
4. "size_band" is one of micro | small | mid | large | very large, or null when score is 0.
5. "employee_count" is an approximate headcount ONLY when the findings support
   it, otherwise null. Never guess a number.

Respond with strict JSON only, no prose outside the object:
{"score": <int 0-100>, "size_band": <string|null>, "employee_count": <int|null>, "reasoning": "<one or two sentences>"}
PROMPT;
    }

    /**
     * @param  string|null  $countryRegion
     * @param  array<string, mixed>  $findings
     */
    private function userPrompt(string $company, ?string $countryRegion, array $findings): string
    {
        $summary = $this->promptExcerpt(trim((string) ($findings['summary'] ?? '')));

        $lines = [];
        foreach ((array) ($findings['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }

            $title = trim((string) ($source['title'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));

            if ($title === '' && $url === '') {
                continue;
            }

            $lines[] = $title !== '' && $url !== ''
                ? "- {$title} ({$url})"
                : ($title !== '' ? "- {$title}" : "- {$url}");
        }

        $limitations = $findings['limitations'] ?? null;
        $limitations = is_string($limitations) && $limitations !== '' ? $limitations : 'none stated';

        $context = $countryRegion !== null ? " (disambiguation only: {$countryRegion})" : '';

        return <<<PROMPT
Target company: {$company}{$context}

Public research findings:
- outcome: {$findings['outcome']}
- summary: {$summary}
- sources:
{$this->bulletList($lines)}
- limitations: {$limitations}

Estimate the company's size per the rubric and return the JSON.
PROMPT;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function bulletList(array $lines): string
    {
        if ($lines === []) {
            return '  (none)';
        }

        return implode("\n", array_map(fn (string $line) => "  {$line}", $lines));
    }

    /**
     * The stored findings keep the FULL extraction (feature 011); only the copy
     * embedded into this AI prompt is bounded to the single-call input size so
     * a very long extraction cannot blow the request.
     */
    private function promptExcerpt(string $summary): string
    {
        $limit = max(1000, (int) config('web_research.summary_max_input_chars', 8000));

        if (mb_strlen($summary) <= $limit) {
            return $summary;
        }

        return mb_substr($summary, 0, $limit).'…';
    }
}