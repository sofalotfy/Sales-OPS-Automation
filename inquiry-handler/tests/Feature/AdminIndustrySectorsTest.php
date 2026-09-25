<?php

namespace Tests\Feature;

use App\Models\IndustrySector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\UpstreamStubs as Stubs;
use Tests\TestCase;

/**
 * Admin industry-sectors API (contracts/sectors-admin.md, feature 012 US3):
 * bearer-verified GET/POST/PUT/DELETE over the seeded catalog.
 */
class AdminIndustrySectorsTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(): array
    {
        return ['Authorization' => 'Bearer token'];
    }

    // -------------------------------------------------------------- auth

    public function test_missing_token_returns_401(): void
    {
        $this->getJson('/admin/sectors')
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Not authenticated.');

        Http::assertNothingSent();
    }

    public function test_invalid_token_returns_401(): void
    {
        Stubs::authVerifyRejected();

        $this->getJson('/admin/sectors', $this->bearer())
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Not authenticated.');
    }

    // ------------------------------------------------------------- index

    public function test_index_lists_the_seeded_catalog_ordered_by_rating(): void
    {
        Stubs::authVerifyOk();

        $response = $this->getJson('/admin/sectors', $this->bearer());

        $response->assertOk()
            ->assertJsonCount(24, 'items')
            ->assertJsonPath('items.0.name', 'E-commerce & Retail')
            ->assertJsonPath('items.0.rating', 92)
            ->assertJsonPath('items.1.name', 'SaaS & Software');

        $ratings = array_column($response->json('items'), 'rating');
        $sorted = $ratings;
        rsort($sorted);
        $this->assertSame($sorted, $ratings, 'Sectors must be listed rating-desc.');
    }

    public function test_create_round_trips_into_the_catalog(): void
    {
        Stubs::authVerifyOk();

        $response = $this->postJson('/admin/sectors', ['name' => 'Pharmacovigilance', 'rating' => 61], $this->bearer());

        $response->assertStatus(201)
            ->assertJsonPath('sector.name', 'Pharmacovigilance')
            ->assertJsonPath('sector.rating', 61);

        $this->assertDatabaseHas('industry_sectors', [
            'name' => 'Pharmacovigilance',
            'rating' => 61,
        ]);
    }

    public function test_update_round_trips_into_the_catalog(): void
    {
        Stubs::authVerifyOk();
        $sector = IndustrySector::query()->where('name', 'E-commerce & Retail')->first();

        $response = $this->putJson("/admin/sectors/{$sector->id}", ['name' => 'E-commerce & Marketplaces', 'rating' => 91], $this->bearer());

        $response->assertOk()
            ->assertJsonPath('sector.name', 'E-commerce & Marketplaces')
            ->assertJsonPath('sector.rating', 91);

        $this->assertDatabaseHas('industry_sectors', [
            'id' => $sector->id,
            'name' => 'E-commerce & Marketplaces',
            'rating' => 91,
        ]);
    }

    public function test_delete_hard_removes_the_row(): void
    {
        Stubs::authVerifyOk();
        $sector = IndustrySector::query()->where('name', 'Telecom')->first();

        $this->deleteJson("/admin/sectors/{$sector->id}", [], $this->bearer())
            ->assertStatus(204);

        $this->assertDatabaseMissing('industry_sectors', ['id' => $sector->id]);
        $this->assertSame(23, IndustrySector::query()->count());
    }

    // ---------------------------------------------------------- validation

    public function test_blank_name_returns_422(): void
    {
        Stubs::authVerifyOk();

        $this->postJson('/admin/sectors', ['name' => '   ', 'rating' => 50], $this->bearer())
            ->assertStatus(422)
            ->assertJsonPath('detail', 'Name is required.');
    }

    public function test_too_long_name_returns_422(): void
    {
        Stubs::authVerifyOk();

        $this->postJson('/admin/sectors', ['name' => str_repeat('a', 101), 'rating' => 50], $this->bearer())
            ->assertStatus(422)
            ->assertJsonPath('detail', 'Name must be 100 characters or fewer.');
    }

    public function test_case_insensitive_duplicate_name_returns_422(): void
    {
        Stubs::authVerifyOk();

        $this->postJson('/admin/sectors', ['name' => 'FINTECH', 'rating' => 50], $this->bearer())
            ->assertStatus(422)
            ->assertJsonPath('detail', 'A sector with that name already exists.');
    }

    public function test_update_may_keep_its_own_name(): void
    {
        Stubs::authVerifyOk();
        $sector = IndustrySector::query()->where('name', 'Fintech')->first();

        $this->putJson("/admin/sectors/{$sector->id}", ['name' => 'Fintech', 'rating' => 90], $this->bearer())
            ->assertOk();
    }

    public function test_rating_outside_0_100_returns_422(): void
    {
        Stubs::authVerifyOk();

        foreach ([-1, 101] as $rating) {
            $this->postJson('/admin/sectors', ['name' => 'Some Sector', 'rating' => $rating], $this->bearer())
                ->assertStatus(422)
                ->assertJsonPath('detail', 'Rating must be an integer between 0 and 100.');
        }
    }

    public function test_non_integer_rating_returns_422(): void
    {
        Stubs::authVerifyOk();

        $this->postJson('/admin/sectors', ['name' => 'Some Sector', 'rating' => 10.5], $this->bearer())
            ->assertStatus(422)
            ->assertJsonPath('detail', 'Rating must be an integer between 0 and 100.');
    }

    // ---------------------------------------------------------------- 404

    public function test_update_unknown_id_returns_404(): void
    {
        Stubs::authVerifyOk();

        $this->putJson('/admin/sectors/99999', ['name' => 'Nope', 'rating' => 50], $this->bearer())
            ->assertStatus(404)
            ->assertJsonPath('detail', 'Sector not found.');
    }

    public function test_delete_unknown_id_returns_404(): void
    {
        Stubs::authVerifyOk();

        $this->deleteJson('/admin/sectors/99999', [], $this->bearer())
            ->assertStatus(404)
            ->assertJsonPath('detail', 'Sector not found.');
    }

    // ---------------------------------------------------------------- 503

    public function test_store_failure_returns_503(): void
    {
        Stubs::authVerifyOk();
        Schema::drop('industry_sectors');

        $this->postJson('/admin/sectors', ['name' => 'Ghost Sector', 'rating' => 50], $this->bearer())
            ->assertStatus(503)
            ->assertJsonPath('detail', 'Catalog store unavailable.');
    }
}