<?php

namespace Tests\Feature\Support;

use App\Services\NotifyClientGrowthDirector;

/**
 * Records the Client Growth Director notifications a factor emits instead of
 * sending them, so a test can assert the review-decision payload (reason +
 * candidates + anonymized evidence) that accompanies a dropped factor.
 */
class SpyNotifyClientGrowthDirector extends NotifyClientGrowthDirector
{
    /** @var array<int, array{reason: string, context: array<string, mixed>}> */
    public array $calls = [];

    public function notify(string $reason, array $context): void
    {
        $this->calls[] = ['reason' => $reason, 'context' => $context];
    }
}
