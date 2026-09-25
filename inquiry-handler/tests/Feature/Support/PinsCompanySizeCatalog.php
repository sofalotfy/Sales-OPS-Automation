<?php

namespace Tests\Feature\Support;

use App\Scoring\CompanySizeFactor;
use App\Scoring\FactorRegistry;

/**
 * Pins the factor catalog to `company_size` only (its feature-009 state) for
 * feature suites that assert exact engine outcomes while testing something else
 * (scope gate, research middleware, failure paths, the triage envelope).
 *
 * The real catalog now also contains `industry_sector` (feature 012), which
 * would change deterministic single-factor expectations in those suites; this
 * trait keeps them isolated without weakening their intent.
 */
trait PinsCompanySizeCatalog
{
    protected function pinCompanySizeCatalog(): void
    {
        $registry = new FactorRegistry;
        $registry->add($this->app->make(CompanySizeFactor::class));

        $this->app->instance(FactorRegistry::class, $registry);
    }
}