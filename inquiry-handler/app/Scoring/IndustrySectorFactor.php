<?php

namespace App\Scoring;

use App\Scoring\Exceptions\CannotClassifySectorException;
use App\Services\AiCallingService;
use App\Services\IndustrySectorService;
use App\Services\NotifyClientGrowthDirector;

/**
 * The `industry_sector` factor (feature 012, spec US1/US2).
 *
 * US1 — confident match: the factor asks a closed-list classifier over the live
 * catalog names (messaging + bounded web-research findings + company/country
 * disambiguation only). Every returned name is validated to exist in the
 * catalog (no fabrication); the highest-rated match wins, ties break
 * alphabetically (FR-003), and the verdict score is EXACTLY the matched
 * sector's stored rating (data-model.md).
 *
 * US2 — no fabrication (FR-004/FR-005): any inability to classify (empty
 * catalog, no plausible sector, unavailable/malformed AI output) builds the
 * review-decision payload (reason + candidate sectors + a short anonymized
 * evidence excerpt — never first/last/email/phone, FR-012), notifies the
 * Client Growth Director, then throws `CannotClassifySectorException`. The
 * engine records the drop and renormalizes; the triage response stays 200.
 */
final class IndustrySectorFactor implements ScoreFactor
{
    private const DROP_NO_SECTORS = 'no sectors configured';
    private const DROP_UNAVAILABLE = 'classification failed (sector estimate unavailable)';
    private const DROP_NO_PLAUSIBLE = 'classification failed (no sector in catalog plausible)';

    private const EXCERPT_LIMIT = 2000;

    /** Safety clamp for catalog descriptions; the admin API already caps at 500. */
    private const PROMPT_DESCRIPTION_LIMIT = 500;

    public function __construct(
        private readonly IndustrySectorService $sectors,
        private readonly AiCallingService $ai,
        private readonly NotifyClientGrowthDirector $notifier,
    ) {
    }

    public function name(): string
    {
        return 'industry_sector';
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array<string, mixed>  $context
     */
    public function score(array $inquiry, array $context): FactorVerdict
    {
        $catalog = $this->sectors->activeMap();

        if ($catalog === []) {
            $this->drop(self::DROP_NO_SECTORS, [], $inquiry, $context);
        }

        $decoded = $this->ai->complete(
            $this->systemPrompt(),
            $this->userPrompt($inquiry, $context, $catalog),
        );

        if (! is_array($decoded)) {
            $this->drop(self::DROP_UNAVAILABLE, $this->candidateNames($decoded), $inquiry, $context);
        }

        $matched = $this->matchNames($decoded, $catalog);

        if ($matched === null) {
            $this->drop(self::DROP_UNAVAILABLE, $this->candidateNames($decoded), $inquiry, $context);
        }

        if ($matched === []) {
            $this->drop(self::DROP_NO_PLAUSIBLE, $this->candidateNames($decoded), $inquiry, $context);
        }

        $winner = $matched[0];
        $others = array_slice($matched, 1);
        $rating = $catalog[$winner];

        $reasoning = sprintf('Matches industry sector "%s" (%d/100).', $winner, $rating);

        if ($others !== []) {
            $reasoning .= ' Also considered: "'.implode('", "', $others).'".';
        }

        if ($this->hasTie($matched, $catalog)) {
            $reasoning .= sprintf(' Rating tie broken alphabetically to "%s".', $winner);
        }

        return new FactorVerdict($rating, $reasoning, ['sector' => $winner]);
    }

    /**
     * @param  array<string, int>  $catalog  name → rating
     *
     * @return array<int, string>|null  canonical catalog names, best first;
     *                                  null when the AI output was malformed
     */
    private function matchNames(array $decoded, array $catalog): ?array
    {
        $sectors = $decoded['sectors'] ?? null;

        if (! is_array($sectors)) {
            return null;
        }

        $canonical = [];

        foreach ($sectors as $value) {
            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            $name = $this->canonicalName(trim($value), $catalog);

            if ($name !== null && ! in_array($name, $canonical, true)) {
                $canonical[] = $name;
            }
        }

        usort($canonical, function (string $a, string $b) use ($catalog) {
            if ($catalog[$a] !== $catalog[$b]) {
                return $catalog[$b] <=> $catalog[$a];
            }

            return strcmp($a, $b);
        });

        return $canonical;
    }

    /**
     * Map a classifier-emitted name back to its canonical stored spelling
     * (case/whitespace-insensitive) so fabricated names never pass (US2).
     *
     * @param  array<string, int>  $catalog
     */
    private function canonicalName(string $name, array $catalog): ?string
    {
        $normalized = mb_strtolower($name);

        foreach (array_keys($catalog) as $stored) {
            if (mb_strtolower($stored) === $normalized) {
                return $stored;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $matched
     * @param  array<string, int>  $catalog
     */
    private function hasTie(array $matched, array $catalog): bool
    {
        if (count($matched) < 2) {
            return false;
        }

        $winnerRating = $catalog[$matched[0]];

        foreach (array_slice($matched, 1) as $name) {
            if ($catalog[$name] === $winnerRating) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidate sectors the classifier says it considered (US2 payload), mapped
     * to canonical catalog names; falls back to nothing when unparseable.
     *
     * @return array<int, string>
     */
    private function candidateNames(array|string|null $decoded): array
    {
        $considered = is_array($decoded) ? ($decoded['considered'] ?? null) : null;

        if ($considered === null) {
            return [];
        }

        if (! is_array($considered)) {
            return [];
        }

        $catalog = $this->sectors->activeMap();
        $names = [];

        foreach ($considered as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $name = $this->canonicalName(trim($value), $catalog) ?? trim($value);

            if (! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array<string, mixed>  $context
     */
    private function evidenceExcerpt(array $inquiry, array $context): string
    {
        $parts = [];

        $message = trim((string) ($inquiry['message'] ?? ''));
        if ($message !== '') {
            $parts[] = $message;
        }

        $findings = is_array($context['web_research']['findings'] ?? null)
            ? $context['web_research']['findings']
            : null;

        $title = trim((string) ($findings['title'] ?? ''));
        if ($title !== '') {
            $parts[] = $title;
        }

        $excerpt = $this->truncate($this->anonymize(implode("\n", $parts)));

        return $excerpt === '' ? '(no evidence available)' : $excerpt;
    }

    /**
     * The review-decision excerpt is anonymized by construction (it only ever
     * carries message + findings), but scrub obvious contact patterns anyway so
     * a user-pasted phone/email inside the message cannot reach the notifier.
     */
    private function anonymize(string $text): string
    {
        $text = preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\+?\d[\d\s().-]{6,}\d/', '[redacted]', $text) ?? $text;

        return $text;
    }

    private function truncate(string $text): string
    {
        $limit = (int) config('scoring.sector_evidence_excerpt_chars', self::EXCERPT_LIMIT);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit).'…';
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array<string, mixed>  $context
     */
    private function drop(string $reason, array $candidates, array $inquiry, array $context): never
    {
        $this->notifier->notify($reason, [
            'reason' => $reason,
            'candidates' => $candidates,
            'evidence_excerpt' => $this->evidenceExcerpt($inquiry, $context),
        ]);

        throw new CannotClassifySectorException($reason);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a sector classifier for a B2B sales triage engine.

Given a sales inquiry (message) plus optional public research findings, decide
which of the catalog industry sectors is the best fit, using each sector's
rating only as a prior — the fit must come from the evidence.

Output strict JSON only, no prose outside the object:
{"sectors": ["Catalog Sector Name", ...], "considered": ["Catalog Sector Name", ...], "reasoning": "<one sentence>"}

Rules:
1. Use catalog names EXACTLY as written — never invent or rename a sector.
2. "sectors" is the ordered, non-empty list of catalog names that plausibly fit
   the inquiry, best fit first (usually a single sector).
3. "considered" lists every catalog name you reviewed but did NOT select.
4. If NO catalog sector is a plausible fit, return "sectors": [] and explain in
   "reasoning".
PROMPT;
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array<string, mixed>  $context
     * @param  array<string, int>  $catalog  name → rating
     */
    private function userPrompt(array $inquiry, array $context, array $catalog): string
    {
        $message = $this->truncate(trim((string) ($inquiry['message'] ?? '')));

        $disambiguation = [];
        $company = trim((string) ($inquiry['company_name'] ?? ''));
        if ($company !== '') {
            $disambiguation[] = $company;
        }
        $country = trim((string) ($inquiry['country_region'] ?? ''));
        if ($country !== '') {
            $disambiguation[] = $country;
        }

        $descriptions = $this->sectors->activeDescriptions();

        $lines = [];
        foreach ($catalog as $name => $rating) {
            $description = mb_substr((string) ($descriptions[$name] ?? ''), 0, self::PROMPT_DESCRIPTION_LIMIT);

            $lines[] = $description === ''
                ? "- {$name} ({$rating})"
                : "- {$name} ({$rating}): {$description}";
        }

        $linesText = $lines === [] ? '  (none)' : implode("\n", $lines);

        $findingsText = $this->findingsHeader($context);

        $disambiguationText = $disambiguation !== []
            ? 'Disambiguation only: '.implode(' / ', $disambiguation).'.'
            : 'None.';

        return <<<PROMPT
Message:
{$message}

Public research findings:
{$findingsText}

Company context ({$disambiguationText})

Catalog (name → rating, and what each sector covers):
{$linesText}

Classify the inquiry's industry sector per the rules and return the JSON.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function findingsHeader(array $context): string
    {
        $findings = is_array($context['web_research']['findings'] ?? null)
            ? $context['web_research']['findings']
            : null;

        if ($findings === null) {
            return '- (no web-research findings available)';
        }

        $outcome = (string) ($findings['outcome'] ?? 'completed');
        $summary = $this->truncate($this->anonymize(trim((string) ($findings['summary'] ?? ''))));
        $summary = $summary === '' ? '(no summary)' : $summary;

        $sourceLines = [];
        foreach ((array) ($findings['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }

            $title = trim((string) ($source['title'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));

            if ($title !== '' && $url !== '') {
                $sourceLines[] = "- {$title} ({$url})";
            } elseif ($title !== '') {
                $sourceLines[] = "- {$title}";
            } elseif ($url !== '') {
                $sourceLines[] = "- {$url}";
            }
        }

        $sources = $sourceLines !== [] ? implode("\n", $sourceLines) : '- (none)';

        return "- outcome: {$outcome}\n- summary: {$summary}\n- sources:\n{$sources}";
    }
}