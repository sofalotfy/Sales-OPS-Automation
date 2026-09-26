<?php

namespace App\Enums;

/**
 * Lifecycle of one run on the classification log (feature 013,
 * data-model.md). The row is created `queued` by POST /inquiry/enqueue (for
 * concurrent CRM runs) or as soon as a synchronous /inquiry/triage request is
 * accepted, then advances through each pipeline stage as its data lands:
 *
 *   queued → processing → researching → scope_check → scoring → succeeded
 *                                                        └─────────→ failed
 *
 * A stage's status is set just before that stage runs; the stage's data is
 * written when the stage completes, so a poll can observe e.g. `scope_check`
 * with the `web_research` columns already populated. A decline short-circuits
 * mid-pipeline to `succeeded` with a disqualify envelope + `refusal`; an
 * unrecoverable run lands `failed` with `error` set and stops spending.
 */
enum InquiryRunStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Researching = 'researching';
    case ScopeCheck = 'scope_check';
    case Scoring = 'scoring';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
