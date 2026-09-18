<?php

namespace App\WebResearch;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates the web-research step (mirrors App\ScopeGate\ScopeCheckService).
 *
 * Builds contact-safe lookup criteria from the inquiry (company name +
 * country/region + person's name with company context — never email/phone),
 * delegates the actual lookups to the injected WebResearchProvider, then hands
 * the raw payload to the AI ResearchAgent (feature 010), which filters it to
 * the named entity, fetches the kept sources, and summarizes them. Maps the
 * outcome onto a WebResearchVerdict:
 *
 *  - provider throws / is unavailable / returns unusable → indeterminate
 *    (fail open, mirroring the scope gate's FR-007 behavior);
 *  - provider returns a `decline` block with reason + refusal → decline;
 *  - agent fails open → indeterminate;
 *  - otherwise → accept carrying the agent's `{outcome, summary, sources}`.
 *
 * run() returns the verdict together with the criteria that were sent, so a
 * declined/persisted record can reconstruct exactly what was searched
 * (accountability, same contract as the scope gate's `retrieved`).
 */
class WebResearchService
{
    public function __construct(
        private readonly WebResearchProvider $provider,
        private readonly ResearchAgent $agent,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @return array{verdict: WebResearchVerdict, criteria: array{company: array{name: string, country_region: string|null}|null, person: array{first_name: string, last_name: string, country_region?: string, company_context?: string}}, audit: array<string, mixed>}
     */
    public function run(array $inquiry): array
    {
        $criteria = $this->criteria($inquiry);

        try {
            $research = $this->provider->research($criteria);
        } catch (Throwable $e) {
            Log::error('Web research provider failed.', [
                'error' => $e->getMessage(),
            ]);

            return [
                'verdict' => WebResearchVerdict::indeterminate(
                    'Web research was unavailable, so no company/person findings were produced.',
                ),
                'criteria' => $criteria,
                'audit' => [],
            ];
        }

        $findings = $this->normalize($research);
        $decline = $research['decline'] ?? null;

        if (is_array($decline) && is_string($decline['reason'] ?? null) && is_string($decline['refusal'] ?? null)) {
            return [
                'verdict' => WebResearchVerdict::decline(
                    $findings,
                    trim($decline['reason']),
                    trim($decline['refusal']),
                ),
                'criteria' => $criteria,
                'audit' => [],
            ];
        }

        $result = $this->agent->research($criteria, $research);

        if ($result->outcome->isIndeterminate()) {
            return [
                'verdict' => WebResearchVerdict::indeterminate($result->summary),
                'criteria' => $criteria,
                'audit' => $result->audit,
            ];
        }

        return [
            'verdict' => WebResearchVerdict::accept($result->toFindings()),
            'criteria' => $criteria,
            'audit' => $result->audit,
        ];
    }

    /**
     * Build the contact-safe lookup criteria for one inquiry (spec 009 US3).
     *
     * Only the fields a public lookup may legally/ethically use are included:
     * the company name (+ optional country/region to disambiguate) and the
     * person's first/last name with the company used as disambiguation context.
     * `email` and `phone_number` are never part of the criteria — asserted by
     * the privacy unit test.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @return array{company: array{name: string, country_region: string|null}|null, person: array{first_name: string, last_name: string, country_region?: string, company_context?: string}}
     */
    public function criteria(array $inquiry): array
    {
        $person = [
            'first_name' => $inquiry['first_name'],
            'last_name' => $inquiry['last_name'],
        ];

        $country = $inquiry['country_region'] ?? null;
        if (is_string($country) && $country !== '') {
            $person['country_region'] = $country;
        }

        $company = $inquiry['company_name'] ?? null;
        if (! is_string($company) || $company === '') {
            return ['company' => null, 'person' => $person];
        }

        $person['company_context'] = $company;

        return [
            'company' => [
                'name' => $company,
                'country_region' => is_string($country) && $country !== '' ? $country : null,
            ],
            'person' => $person,
        ];
    }

    /**
     * Normalize a provider payload into the stable findings shape the verdict
     * and the persisted record carry: `company` / `person` always present.
     *
     * @return array{company: array<mixed>, person: array<mixed>}
     */
    private function normalize(mixed $research): array
    {
        if (! is_array($research)) {
            return ['company' => [], 'person' => []];
        }

        return [
            'company' => is_array($research['company'] ?? null) ? $research['company'] : [],
            'person' => is_array($research['person'] ?? null) ? $research['person'] : [],
        ];
    }
}
