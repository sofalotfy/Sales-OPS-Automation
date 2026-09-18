<?php

namespace App\Http\Controllers;

use App\Services\FactorSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin weights API (contracts/factor-settings-admin.md), gated by
 * VerifyUpstreamToken. GET lists the effective weight of every registered
 * factor; PUT replaces the stored weights for registered names, rejecting
 * unknown names (FR-004) and non-numeric/negative weights with 422.
 */
class AdminFactorSettingsController extends Controller
{
    public function __construct(private readonly FactorSettingsService $settings)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->settings->effectiveWeights());
    }

    public function update(Request $request): JsonResponse
    {
        $weights = $request->json('weights');

        if (! is_array($weights)) {
            return response()->json(['detail' => 'Weights are required.'], 422);
        }

        // A JSON array (numeric keys) is not the documented name→number object.
        if (array_is_list($weights)) {
            return response()->json(['detail' => 'Weights must be an object keyed by factor name.'], 422);
        }

        foreach ($weights as $name => $weight) {
            if (! is_string($name) || $name === '') {
                return response()->json(['detail' => 'Weights must be keyed by factor name.'], 422);
            }

            if (! $this->settings->hasFactor($name)) {
                return response()->json(['detail' => "Unknown factor: {$name}"], 422);
            }

            if (! is_int($weight) && ! is_float($weight) && ! (is_string($weight) && is_numeric($weight))) {
                return response()->json(['detail' => "Weight for {$name} must be a number >= 0."], 422);
            }

            $value = (float) $weight;

            if (! is_finite($value) || $value < 0) {
                return response()->json(['detail' => "Weight for {$name} must be a number >= 0."], 422);
            }
        }

        try {
            $factors = $this->settings->updateWeights($weights);
        } catch (Throwable $e) {
            Log::error('Failed to persist factor settings.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['detail' => 'Settings store unavailable.'], 503);
        }

        return response()->json(['factors' => $factors]);
    }
}