<?php

namespace Tests\Unit;

use App\Models\Factor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_instance_creates_the_singleton_seed_row(): void
    {
        $factor = Factor::instance();

        $this->assertSame(1, $factor->getKey());
        $this->assertSame([], $factor->weights());

        // A second call resolves the same single row (no duplicates).
        $this->assertSame(1, Factor::query()->count());
        $this->assertSame(1, Factor::instance()->getKey());
    }

    public function test_weight_for_returns_stored_weight(): void
    {
        $factor = Factor::instance();
        $factor->update(['weights' => ['scope_relevance' => 0.5, 'urgency' => 2]]);

        $this->assertSame(0.5, $factor->weightFor('scope_relevance'));
        $this->assertSame(2.0, $factor->weightFor('urgency'));
    }

    public function test_weight_for_returns_null_for_unknown_or_bad_values(): void
    {
        $factor = Factor::instance();
        $factor->update(['weights' => ['scope_relevance' => 0.5, 'broken' => 'nope', 'zero' => 0]]);

        $this->assertNull($factor->weightFor('missing'));
        $this->assertNull($factor->weightFor('broken'));
        $this->assertSame(0.0, $factor->weightFor('zero'));
    }

    public function test_stored_weight_is_picked_up_by_a_fresh_instance(): void
    {
        Factor::instance()->update(['weights' => ['scope_relevance' => 0.75]]);

        $fresh = Factor::instance();

        $this->assertSame(0.75, $fresh->weightFor('scope_relevance'));
    }

    public function test_weights_with_unknown_keys_are_retained_but_ignored_on_read(): void
    {
        $factor = Factor::instance();
        $factor->update(['weights' => ['made_up_factor' => 9]]);

        $this->assertSame(['made_up_factor' => 9], $factor->weights());
        $this->assertNull($factor->weightFor('made_up_factor_registered_name_elsewhere'));
    }
}