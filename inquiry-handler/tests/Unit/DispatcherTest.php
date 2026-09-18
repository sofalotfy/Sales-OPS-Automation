<?php

namespace Tests\Unit;

use App\Triage\Disposition;
use App\Triage\Dispatcher;
use App\Triage\Handlers\BookingHandler;
use App\Triage\Handlers\DeclineHandler;
use App\Triage\Handlers\EscalateHandler;
use Tests\TestCase;

class DispatcherTest extends TestCase
{
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.booking_url', 'https://book.example.com/meet');
        $this->dispatcher = new Dispatcher(
            new DeclineHandler(),
            new EscalateHandler(),
            new BookingHandler(new EscalateHandler()),
        );
    }

    public function test_known_decline_routes_to_decline_handler(): void
    {
        $result = $this->dispatcher->dispatch(Disposition::Decline, ['inquiry' => []]);

        $this->assertSame(Disposition::Decline, $result->disposition);
    }

    public function test_known_escalate_routes_to_escalate_handler(): void
    {
        $result = $this->dispatcher->dispatch(Disposition::Escalate, ['inquiry' => []]);

        $this->assertSame(Disposition::Escalate, $result->disposition);
    }

    public function test_known_booking_routes_to_booking_handler(): void
    {
        $result = $this->dispatcher->dispatch(Disposition::Booking, ['inquiry' => []]);

        $this->assertSame(Disposition::Booking, $result->disposition);
    }

    public function test_null_disposition_falls_back_to_escalate(): void
    {
        $result = $this->dispatcher->dispatch(null, ['inquiry' => ['message' => 'x']]);

        $this->assertSame(Disposition::Escalate, $result->disposition);
        $this->assertSame('x', $result->context['inquiry']['message']);
    }

    public function test_unparseable_values_normalize_to_escalate(): void
    {
        // Any value outside the enum normalizes to null before dispatch
        // (Disposition::tryFrom in InquiryTriageService); the dispatcher's
        // null/default arm is the escalate-by-default safety net (FR-010).
        $this->assertNull(Disposition::tryFrom('book_me_free_cruise'));
        $this->assertNull(Disposition::tryFrom(''));

        $result = $this->dispatcher->dispatch(null, ['inquiry' => ['message' => 'x']]);

        $this->assertSame(Disposition::Escalate, $result->disposition);
    }
}