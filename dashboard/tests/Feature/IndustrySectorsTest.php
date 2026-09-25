<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs;
use Tests\TestCase;

/**
 * "Industry sectors" tab (feature 012 US3): manage the inquiry handler's sector
 * catalog over the bearer-verified admin API. HTTP-only — the dashboard never
 * touches the catalog store directly. A connection timeout (inquiry handler
 * unreachable) degrades the tab to an inline error; a 401 from upstream sends
 * the user back to login.
 */
class IndustrySectorsTest extends TestCase
{
    public function test_index_requires_authentication(): void
    {
        $this->get(route('sectors.index'))->assertRedirect(route('login.show'));
    }

    public function test_index_renders_catalog_rows(): void
    {
        $this->signIn();
        UpstreamStubs::fakeIndustrySectors([
            UpstreamStubs::sector(id: 1, name: 'E-commerce & Retail', rating: 92),
            UpstreamStubs::sector(id: 2, name: 'Fintech', rating: 88),
        ]);

        $this->get(route('sectors.index'))
            ->assertOk()
            ->assertSee('Industry sectors')
            ->assertSee('E-commerce & Retail')
            ->assertSee('Fintech')
            ->assertSee('2 sectors');
    }

    public function test_index_empty_state_shows_add_prompt(): void
    {
        $this->signIn();
        UpstreamStubs::fakeIndustrySectors([]);

        $this->get(route('sectors.index'))
            ->assertOk()
            ->assertSee('No sectors in the catalog yet');
    }

    public function test_index_surfaces_upstream_failure_inline(): void
    {
        $this->signIn();
        UpstreamStubs::fakeIndustrySectorsUnavailable();

        $this->get(route('sectors.index'))
            ->assertOk()
            ->assertSee('Catalog store unavailable.');
    }

    public function test_index_connection_timeout_shows_inline_error(): void
    {
        $this->signIn();
        Http::fake([
            UpstreamStubs::inquiryUrl('/admin/sectors') => fn () => throw new ConnectionException('timed out'),
        ]);

        $this->get(route('sectors.index'))
            ->assertOk()
            ->assertSee('The sector catalog is unavailable');
    }

    public function test_index_upstream_401_redirects_to_login(): void
    {
        $this->signIn();
        Http::fake([
            UpstreamStubs::inquiryUrl('/admin/sectors') => Http::response(['detail' => 'Not authenticated.'], 401),
        ]);

        $this->get(route('sectors.index'))->assertRedirect(route('login.show'));
    }

    public function test_store_creates_a_sector_and_flashes_status(): void
    {
        $this->signIn();
        UpstreamStubs::fakeIndustrySectorCreate(UpstreamStubs::sector(id: 3, name: 'Pharma Analytics', rating: 66));

        $this->from(route('sectors.index'))
            ->post(route('sectors.store'), ['name' => '  Pharma Analytics  ', 'rating' => 66])
            ->assertRedirect(route('sectors.index'))
            ->assertSessionHas('status', 'Sector created.');

        // The name is trimmed before it leaves the dashboard.
        Http::assertSent(fn (Request $request) => $request['name'] === 'Pharma Analytics');
    }

    public function test_store_surfaces_validation_detail(): void
    {
        $this->signIn();
        UpstreamStubs::fakeIndustrySectorRejected('A sector with that name already exists.');

        $this->from(route('sectors.index'))
            ->post(route('sectors.store'), ['name' => 'Fintech', 'rating' => 90])
            ->assertRedirect(route('sectors.index'))
            ->assertSessionHasErrors('sectors');
    }

    public function test_update_edits_a_sector(): void
    {
        $this->signIn();
        UpstreamStubs::fakeIndustrySectorUpdate(UpstreamStubs::sector(id: 1, name: 'E-commerce & Marketplaces', rating: 91));

        $this->from(route('sectors.index'))
            ->put(route('sectors.update', 1), ['name' => 'E-commerce & Marketplaces', 'rating' => 91])
            ->assertRedirect(route('sectors.index'))
            ->assertSessionHas('status', 'Sector updated.');
    }

    public function test_destroy_deletes_a_sector(): void
    {
        $this->signIn();
        UpstreamStubs::fakeIndustrySectorDelete();

        $this->from(route('sectors.index'))
            ->delete(route('sectors.destroy', 1))
            ->assertRedirect(route('sectors.index'))
            ->assertSessionHas('status', 'Sector deleted.');
    }

    public function test_write_upstream_401_redirects_to_login(): void
    {
        $this->signIn();
        Http::fake([
            UpstreamStubs::inquiryUrl('/admin/sectors*') => Http::response(['detail' => 'Not authenticated.'], 401),
        ]);

        $this->from(route('sectors.index'))
            ->post(route('sectors.store'), ['name' => 'A New Sector', 'rating' => 50])
            ->assertRedirect(route('login.show'));
    }
}