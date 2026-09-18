<?php

namespace App\Scoring;

/**
 * Fixed interface behind which a factor plugs into the scoring engine
 * (FR-002 / research R4). "Add a factor" = define one service implementing
 * this interface and register it in AppServiceProvider (SC-003).
 *
 * A factor computes a single 0–100 score for the inquiry. It may use AI
 * (AiCallingService::complete()) but is not required to; it always either
 * returns a FactorVerdict or throws, in which case the engine drops it and
 * renormalizes the remaining weights (FR-007).
 */
interface ScoreFactor
{
    /**
     * Stable, dev-registered name. This is the key used in the weights store
     * (Factor::weightFor), in the response factor_scores breakdown, and in
     * the admin factor-settings API (contracts/factor-settings-admin.md).
     */
    public function name(): string;

    /**
     * Compute the factor's score for one inquiry.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone_number?: string|null, company_name?: string|null, country_region?: string|null, message: string}  $inquiry
     * @param  array<string, mixed>  $context  normalized inquiry + retrieved grounding
     */
    public function score(array $inquiry, array $context): FactorVerdict;
}