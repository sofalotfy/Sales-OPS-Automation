<?php

namespace App\Http\Controllers;

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
    public function __construct(private readonly ClassificationResultsService $results)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->integer('limit', 20);
        $limit = max(1, min(50, $limit));
        $offset = max(0, (int) $request->integer('offset', 0));

        try {
            return response()->json($this->results->list($limit, $offset));
        } catch (Throwable $e) {
            Log::error('Failed to list classification results.', ['error' => $e->getMessage()]);

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