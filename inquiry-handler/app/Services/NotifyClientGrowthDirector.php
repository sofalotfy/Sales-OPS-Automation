<?php

namespace App\Services;

/**
 * Notification channel to the Client Growth Director (feature 012, ASS-06).
 *
 * On an unclassifiable inquiry the `industry_sector` factor builds a
 * review-decision payload (reason + candidate sectors considered + a short
 * anonymized evidence excerpt — never first/last/email/phone) and calls
 * `notify()` BEFORE throwing `CannotClassifySectorException`.
 *
 * THIS IS A DOCUMENTED NO-OP STUB.
 *
 * @todo wire a real channel (Slack/email): the drop must be confirmed with the
 *       Client Growth Director before any automated alert is sent (ASS-06 —
 *       alert not yet confirmed with sales).
 */
class NotifyClientGrowthDirector
{
    /**
     * @param  array{reason: string, candidates: array<int, string>, evidence_excerpt: string}  $context
     */
    public function notify(string $reason, array $context): void
    {
        // No-op stub. The event is intentionally swallowed until the growth
        // director confirms the review-decision payload (see class docblock).
    }
}