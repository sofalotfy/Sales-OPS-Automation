<?php

namespace Tests\Unit;

use App\Scoring\FactorRegistry;
use App\Scoring\ScoreFactor;
use Tests\TestCase;
use Tests\Unit\Support\ConfigurableFactor;

class FactorRegistryTest extends TestCase
{
    public function test_starts_empty_and_grows_one_factor_at_a_time(): void
    {
        $registry = new FactorRegistry;

        $this->assertCount(0, $registry);
        $this->assertSame([], $registry->all());

        $registry->add(new ConfigurableFactor('scope_relevance', 88));

        $this->assertCount(1, $registry);
        $this->assertTrue($registry->has('scope_relevance'));
        $this->assertFalse($registry->has('urgency'));
        $this->assertInstanceOf(ScoreFactor::class, $registry->get('scope_relevance'));
        $this->assertNull($registry->get('urgency'));
        $this->assertSame(['scope_relevance'], array_keys($registry->all()));
    }

    public function test_add_same_name_replaces_the_factor(): void
    {
        $registry = new FactorRegistry;
        $registry->add(new ConfigurableFactor('scope_relevance', 10));
        $registry->add(new ConfigurableFactor('scope_relevance', 90));

        $this->assertCount(1, $registry);
        $this->assertSame(90, $registry->get('scope_relevance')?->score([], [])->score);
    }

    public function test_iterating_yields_name_to_factor_pairs(): void
    {
        $registry = new FactorRegistry;
        $registry->add(new ConfigurableFactor('scope_relevance', 60));

        $names = [];
        foreach ($registry as $name => $factor) {
            $names[] = $name;
        }

        $this->assertSame(['scope_relevance'], $names);
    }
}