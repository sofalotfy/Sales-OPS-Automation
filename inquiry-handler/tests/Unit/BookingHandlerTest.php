<?php

namespace Tests\Unit;

use App\Triage\Disposition;
use App\Triage\Handlers\BookingHandler;
use App\Triage\Handlers\EscalateHandler;
use Tests\TestCase;

class BookingHandlerTest extends TestCase
{
    private BookingHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new BookingHandler(new EscalateHandler());
    }

    private function context(): array
    {
        return ['inquiry' => ['message' => 'We want to sign up for a maintenance plan.']];
    }

    public function test_booking_with_configured_link_embeds_it_in_reply(): void
    {
        config()->set('services.booking_url', 'https://book.example.com/meet');

        $result = $this->handler->handle($this->context());

        $this->assertSame(Disposition::Booking, $result->disposition);
        $this->assertStringContainsString('https://book.example.com/meet', $result->reply);
        $this->assertSame('https://book.example.com/meet', $result->context['booking_url']);
    }

    public function test_booking_without_configured_link_escalates_never_broken_link(): void
    {
        config()->set('services.booking_url', '');

        $result = $this->handler->handle($this->context());

        $this->assertSame(Disposition::Escalate, $result->disposition);
        $this->assertStringNotContainsString('http', $result->reply);
        $this->assertSame('booking link not configured', $result->context['fallback_reason']);
    }
}