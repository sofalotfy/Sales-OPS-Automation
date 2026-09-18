<?php

namespace Tests\Unit;

use App\Triage\Disposition;
use App\Triage\Handlers\EscalateHandler;
use PHPUnit\Framework\TestCase;

class EscalateHandlerTest extends TestCase
{
    private EscalateHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new EscalateHandler();
    }

    private function context(): array
    {
        return [
            'inquiry' => [
                'message' => 'We need a custom pricing quote.',
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ],
            'retrieved_context' => ['result_count' => 1, 'results' => [['rank' => 1]]],
        ];
    }

    public function test_escalate_preserves_inquiry_and_retrieved_context(): void
    {
        $result = $this->handler->handle($this->context());

        $this->assertSame(Disposition::Escalate, $result->disposition);
        $this->assertSame('We need a custom pricing quote.', $result->context['inquiry']['message']);
        $this->assertSame('Jane Doe', $result->context['inquiry']['name']);
        $this->assertSame('jane@example.com', $result->context['inquiry']['email']);
        $this->assertSame(1, $result->context['retrieved_context']['result_count']);
    }

    public function test_escalate_reply_informs_the_decision_was_taken(): void
    {
        $result = $this->handler->handle($this->context());

        $this->assertNotSame('', $result->reply);
        $this->assertMatchesRegularExpression('/(escalat|team|touch)/i', $result->reply);
    }

    public function test_escalate_has_no_booking_link_surface(): void
    {
        $result = $this->handler->handle($this->context());

        $this->assertStringNotContainsString('http', $result->reply);
        $this->assertArrayNotHasKey('booking_url', $result->context);
    }
}