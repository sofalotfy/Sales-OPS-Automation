<?php

namespace App\Scoring\Exceptions;

use Exception;

/**
 * Thrown by the `industry_sector` factor (feature 012) when an inquiry cannot
 * be confidently mapped to a catalog sector. The scoring engine catches any
 * factor throw, records `{"name": "industry_sector", "reason": …}` in its
 * dropped factors, and renormalizes the remaining weights (FR-007 / SC-002) —
 * the triage response stays 200.
 */
class CannotClassifySectorException extends Exception
{
}