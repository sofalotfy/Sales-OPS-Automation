<?php

namespace App\Services;

use App\Models\Factor;
use App\Scoring\FactorRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read/write the runtime-editable factor weights (contracts/factor-settings-admin.md).
 *
 * Only weights of code-registered factor NAMES can be stored (FR-004): the
 * catalog is dev-only, so the admin surface can tune existing factors but can
 * never add/remove one. Store unavailability degrades reads to empty/defaults
 * (research R3c) and surfaces as a 503 on the write side (contract).
 */
class FactorSettingsService
{
    public function __construct(private readonly FactorRegistry $registry)
    {
    }

    /**
     * @return array{factors: array<int, array{name: string, weight: float, source: string}>, stored: array<string, mixed>}
     */
    public function effectiveWeights(): array
    {
        return [
            'factors' => $this->effectiveList(),
            'stored' => $this->storedWeights(),
        ];
    }

    /**
     * @return array<int, array{name: string, weight: float, source: string}>
     */
    public function effectiveList(): array
    {
        $default = (float) config('scoring.default_factor_weight', 1.0);
        $stored = $this->storedWeights();

        $list = [];
        foreach (array_keys($this->registry->all()) as $name) {
            if (array_key_exists($name, $stored) && is_numeric($stored[$name])) {
                $list[] = ['name' => $name, 'weight' => (float) $stored[$name], 'source' => 'stored'];
            } else {
                $list[] = ['name' => $name, 'weight' => $default, 'source' => 'default'];
            }
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    public function storedWeights(): array
    {
        try {
            return Factor::instance()->weights();
        } catch (Throwable $e) {
            Log::error('Factor settings store unreadable.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function hasFactor(string $name): bool
    {
        return $this->registry->has($name);
    }

    /**
     * Persist weights for registered factor names only, merging into the stored
     * map (unmentioned factors keep their stored value/default). The controller
     * rejects unknown/non-numeric names with 422 before this runs (FR-004).
     *
     * @param  array<string, mixed>  $weights
     * @return array<int, array{name: string, weight: float, source: string}>
     *
     * @throws Throwable when the store is unwritable (→ 503 in the controller)
     */
    public function updateWeights(array $weights): array
    {
        $merged = $this->storedWeights();

        foreach ($weights as $name => $weight) {
            if ($this->registry->has($name) && is_numeric($weight)) {
                $merged[$name] = (float) $weight;
            }
        }

        Factor::instance()->update(['weights' => $merged]);

        return $this->effectiveList();
    }
}