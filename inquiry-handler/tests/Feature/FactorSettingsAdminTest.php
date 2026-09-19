<?php

namespace Tests\Feature;

use App\Models\Factor;
use App\Scoring\FactorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs as Stubs;
use Tests\TestCase;
use Tests\Unit\Support\ConfigurableFactor;

/**
 * Admin factor-settings API (contracts/factor-settings-admin.md): bearer-verified
 * GET/PUT for the runtime-editable weights of code-registered factors (FR-004).
 */
class FactorSettingsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function setRegistry(string ...$names): void
    {
        $registry = new FactorRegistry;
        foreach ($names as $name) {
            $registry->add(new ConfigurableFactor($name, 50));
        }

        $this->app->instance(FactorRegistry::class, $registry);
    }

    public function test_missing_token_returns_401(): void
    {
        $this->getJson('/admin/factor-settings')
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Not authenticated.');

        Http::assertNothingSent();
    }

    public function test_invalid_token_returns_401(): void
    {
        Stubs::authVerifyRejected();

        $this->getJson('/admin/factor-settings', ['Authorization' => 'Bearer stale-token'])
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Not authenticated.');
    }

    public function test_get_lists_the_registered_company_size_factor_by_default(): void
    {
        Stubs::authVerifyOk();

        $this->getJson('/admin/factor-settings', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('factors', [
                ['name' => 'company_size', 'weight' => 1, 'source' => 'default'],
            ])
            ->assertJsonPath('stored', []);
    }

    public function test_get_lists_registered_factor_and_reflects_stored_weights(): void
    {
        $this->setRegistry('scope_relevance', 'urgency');
        Factor::instance()->update(['weights' => ['scope_relevance' => 0.5]]);
        Stubs::authVerifyOk();

        $this->getJson('/admin/factor-settings', ['Authorization' => 'Bearer token'])
            ->assertOk()
            ->assertJsonPath('stored', ['scope_relevance' => 0.5])
            ->assertJsonPath('factors', [
                ['name' => 'scope_relevance', 'weight' => 0.5, 'source' => 'stored'],
                ['name' => 'urgency', 'weight' => 1, 'source' => 'default'],
            ]);
    }

    public function test_put_updates_stored_weights(): void
    {
        $this->setRegistry('scope_relevance', 'urgency');
        Stubs::authVerifyOk();

        $this->putJson('/admin/factor-settings', ['weights' => ['scope_relevance' => 0.55, 'urgency' => 0.2]], [
            'Authorization' => 'Bearer token',
        ])
            ->assertOk()
            ->assertJsonPath('factors', [
                ['name' => 'scope_relevance', 'weight' => 0.55, 'source' => 'stored'],
                ['name' => 'urgency', 'weight' => 0.2, 'source' => 'stored'],
            ]);

        $this->assertSame(0.55, Factor::instance()->weightFor('scope_relevance'));
    }

    public function test_put_leaves_unmentioned_factors_unchanged(): void
    {
        $this->setRegistry('scope_relevance', 'urgency');
        Factor::instance()->update(['weights' => ['urgency' => 0.9]]);
        Stubs::authVerifyOk();

        $this->putJson('/admin/factor-settings', ['weights' => ['scope_relevance' => 0.55]], [
            'Authorization' => 'Bearer token',
        ])->assertOk();

        $this->assertSame(0.55, Factor::instance()->weightFor('scope_relevance'));
        $this->assertSame(0.9, Factor::instance()->weightFor('urgency'));
    }

    public function test_put_unknown_factor_returns_422(): void
    {
        $this->setRegistry('scope_relevance');
        Stubs::authVerifyOk();

        $response = $this->putJson('/admin/factor-settings', ['weights' => ['made_up_factor' => 0.5]], [
            'Authorization' => 'Bearer token',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('detail', 'Unknown factor: made_up_factor');

        $this->assertDatabaseHas('factor_settings', ['id' => 1]);
        $this->assertArrayNotHasKey('made_up_factor', Factor::instance()->weights());
    }

    public function test_put_non_numeric_weight_returns_422(): void
    {
        $this->setRegistry('scope_relevance');
        Stubs::authVerifyOk();

        $this->putJson('/admin/factor-settings', ['weights' => ['scope_relevance' => 'heavy']], [
            'Authorization' => 'Bearer token',
        ])
            ->assertStatus(422)
            ->assertJsonPath('detail', 'Weight for scope_relevance must be a number >= 0.');
    }

    public function test_put_negative_weight_returns_422(): void
    {
        $this->setRegistry('scope_relevance');
        Stubs::authVerifyOk();

        $this->putJson('/admin/factor-settings', ['weights' => ['scope_relevance' => -1]], [
            'Authorization' => 'Bearer token',
        ])
            ->assertStatus(422)
            ->assertJsonPath('detail', 'Weight for scope_relevance must be a number >= 0.');
    }

    public function test_put_missing_weights_returns_422(): void
    {
        $this->setRegistry('scope_relevance');
        Stubs::authVerifyOk();

        $this->putJson('/admin/factor-settings', [], [
            'Authorization' => 'Bearer token',
        ])
            ->assertStatus(422)
            ->assertJsonPath('detail', 'Weights are required.');
    }

    public function test_put_zero_weight_is_allowed(): void
    {
        $this->setRegistry('scope_relevance');
        Stubs::authVerifyOk();

        $this->putJson('/admin/factor-settings', ['weights' => ['scope_relevance' => 0]], [
            'Authorization' => 'Bearer token',
        ])
            ->assertOk()
            ->assertJsonPath('factors.0.weight', 0);
    }
}