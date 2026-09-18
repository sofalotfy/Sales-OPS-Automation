<?php

namespace Tests\Unit;

use App\Triage\Disposition;
use App\Triage\Handlers\DeclineHandler;
use PHPUnit\Framework\TestCase;

class DeclineHandlerTest extends TestCase
{
    private DeclineHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new DeclineHandler();
    }

    public function test_decline_is_polite_and_explains_scope(): void
    {
        $result = $this->handler->handle([
            'inquiry' => ['message' => 'Can you fix my bicycle?'],
            'retrieved_context' => ['result_count' => 0, 'results' => []],
        ]);

        $this->assertSame(Disposition::Decline, $result->disposition);
        $this->assertStringContainsStringIgnoringCase('scope', $result->reply);
    }

    public function test_decline_contains_no_booking_link(): void
    {
        $result = $this->handler->handle([
            'inquiry' => ['message' => 'Can you fix my bicycle?'],
            'retrieved_context' => ['result_count' => 0, 'results' => []],
        ]);

        $this->assertStringNotContainsString('http', $result->reply);
        $this->assertArrayNotHasKey('booking_url', $result->context);
    }
}