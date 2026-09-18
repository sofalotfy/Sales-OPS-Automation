<?php

namespace App\WebResearch;

/**
 * Clean boundary behind which the web-research implementation plugs in.
 *
 * The shipped implementation is {@see \App\WebResearch\Providers\TavilyResearchProvider}
 * (Tavily search API, key from `services.tavily`); swap it by pointing
 * config('web_research.provider') at your own class (env `WEB_RESEARCH_PROVIDER`).
 *
 * Privacy contract (constitution / spec 009 US3): the $criteria argument is
 * built by {@see WebResearchService::criteria()} and NEVER contains
 * `email` / `phone_number`. Only the person's name plus company context is
 * ever handed to the provider. Implementations MUST NOT receive or send any
 * other contact field.
 *
 * The returned payload is opaque to the flow — only the research agent reads
 * its surface keys (and {@see WebResearchService} reads `decline` to
 * short-circuit):
 *  - `company` (array, may be empty)   public signals about the company
 *  - `person`  (array, may be empty)   public signals about the person
 *  - `decline` (array|null)            `['reason' => string, 'refusal' => string]`
 *                                      to short-circuit the flow into a refusal.
 *
 * Throwing (or returning a non-array) makes the step fail open (indeterminate).
 */
interface WebResearchProvider
{
    /**
     * Search the web for public information about the inquirer's company and
     * person, and return the raw findings payload described above.
     *
     * @param  array{company: array{name: string, country_region: string|null}|null, person: array{first_name: string, last_name: string, country_region?: string, company_context?: string}}  $criteria
     * @return array{company?: array<mixed>, person?: array<mixed>, decline?: array{reason: string, refusal: string}|null}
     */
    public function research(array $criteria): array;
}