<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton factor-settings row (data-model.md): factor name → runtime weight.
 *
 * Factor NAMES never live here — they are registered in code (FactorRegistry,
 * FR-004). Unknown keys stored in `weights` are ignored on read (research R6).
 * The read path (DB unavailable) falling back to the default weight is handled
 * by FactorScorer (research R3c); this model only reads, it never fabricates.
 */
class Factor extends Model
{
    protected $table = 'factor_settings';

    protected $fillable = ['weights'];

    protected function casts(): array
    {
        return [
            'weights' => 'array',
        ];
    }

    /**
     * Fetch or create the single settings row (id = 1). The migration seeds it;
     * this keeps the singleton invariant if the row is ever removed.
     */
    public static function instance(): self
    {
        return static::firstOrCreate(['id' => 1], ['weights' => []]);
    }

    /**
     * @return array<string, mixed>
     */
    public function weights(): array
    {
        $weights = $this->weights;

        return is_array($weights) ? $weights : [];
    }

    /**
     * Stored weight for a factor name, or null when unset. Unknown keys and
     * non-numeric values both resolve to null (→ default weight upstream).
     */
    public function weightFor(string $name): ?float
    {
        $weights = $this->weights();

        if (! array_key_exists($name, $weights)) {
            return null;
        }

        return is_numeric($weights[$name]) ? (float) $weights[$name] : null;
    }
}