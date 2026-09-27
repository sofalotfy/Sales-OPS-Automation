<?php

namespace App\Http\Controllers;

use App\Enums\Classification;
use App\Enums\InquiryRunStatus;
use App\Services\ClassificationResultsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only admin listing/detail for the classification log
 * (contracts/classification-reporting.md), gated by VerifyUpstreamToken like
 * the factor-settings API. The dashboard renders these over HTTP (SC-006).
 */
class AdminClassificationResultsController extends Controller
{
    public function __construct(private readonly ClassificationResultsService $results) {}

    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->integer('limit', 20);
        $limit = max(1, min(50, $limit));
        $offset = max(0, (int) $request->integer('offset', 0));

        // Filters are optional exact matches, validated against the enums rather
        // than passed through: an unrecognised value is a caller bug, and
        // silently returning an unfiltered log would hide it.
        $status = $this->filter($request, 'status', array_map(
            fn (InquiryRunStatus $case): string => $case->value,
            InquiryRunStatus::cases(),
        ));
        $classification = $this->filter($request, 'classification', array_map(
            fn (Classification $case): string => $case->value,
            Classification::cases(),
        ));

        if ($status === false || $classification === false) {
            return response()->json(['detail' => 'Unsupported filter value.'], 422);
        }

        try {
            return response()->json($this->results->list($limit, $offset, $status, $classification));
        } catch (Throwable $e) {
            Log::error('Failed to list classification results.', ['error' => $e->getMessage()]);

            return response()->json(['detail' => 'Classification log unavailable.'], 503);
        }
    }

    /**
     * Reads one filter off the query string.
     *
     * @param  list<string>  $allowed
     * @return string|null the value, null when absent/empty, false when unsupported
     */
    private function filter(Request $request, string $key, array $allowed): string|null|false
    {
        $value = $request->query($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return in_array($value, $allowed, true) ? $value : false;
    }

    public function stats(): JsonResponse
    {
        try {
            return response()->json($this->results->stats());
        } catch (Throwable $e) {
            Log::error('Failed to aggregate classification statistics.', ['error' => $e->getMessage()]);

            return response()->json(['detail' => 'Classification log unavailable.'], 503);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->results->find($id);
        } catch (Throwable $e) {
            Log::error('Failed to read classification result.', ['error' => $e->getMessage()]);

            return response()->json(['detail' => 'Classification log unavailable.'], 503);
        }

        if ($result === null) {
            return response()->json(['detail' => 'Classification result not found.'], 404);
        }

        return response()->json($result);
    }
}
