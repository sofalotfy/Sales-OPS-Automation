<?php

namespace App\WebResearch;

/**
 * The AI research agent's outcome for one run (feature 010).
 *
 * Distinct from the step-level {@see WebResearchVerdict}: a run can complete
 * with nothing found, or fail open, and the step still accepts the inquiry.
 *
 *  - `completed`    → a source-grounded summary was produced (possibly naming
 *                     one entity that could not be established);
 *  - `partial`      → a summary was produced, but some kept sources could not
 *                     be fetched, or the run hit its budget with usable content;
 *  - `not_found`    → no candidate was about the named entity (honest statement);
 *  - `ambiguous`    → the name matches several distinct entities and could not
 *                     be resolved to one (honest statement);
 *  - `indeterminate`→ a provider/AI/fetch step failed and nothing usable was
 *                     gathered (fail open).
 */
enum ResearchOutcome: string
{
    case Completed = 'completed';

    case Partial = 'partial';

    case NotFound = 'not_found';

    case Ambiguous = 'ambiguous';

    case Indeterminate = 'indeterminate';

    public function isIndeterminate(): bool
    {
        return $this === self::Indeterminate;
    }
}
