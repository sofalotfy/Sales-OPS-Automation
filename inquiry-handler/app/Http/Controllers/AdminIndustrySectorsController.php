<?php

namespace App\Http\Controllers;

use App\Services\IndustrySectorService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * Admin industry-sectors API (contracts/sectors-admin.md, feature 012 US3),
 * gated by VerifyUpstreamToken like the factor-settings endpoints.
 *
 * GET lists the catalog; POST creates; PUT updates name/rating/description;
 * DELETE hard-removes a sector (historical classifications keep the frozen name
 * in their factor_scores reasoning — only future runs are affected, FR-010).
 * Validation messages follow the contract verbatim; DB-layer failures surface
 * as 503 "Catalog store unavailable." while unknown ids surface as 404.
 */
class AdminIndustrySectorsController extends Controller
{
    public function __construct(private readonly IndustrySectorService $sectors)
    {
    }

    public function index(): JsonResponse
    {
        try {
            return response()->json(['items' => $this->sectors->all()]);
        } catch (Throwable $e) {
            Log::error('Catalog store unreadable.', ['error' => $e->getMessage()]);

            return $this->unavailable();
        }
    }

    public function store(Request $request): JsonResponse
    {
        [$name, $rating, $description] = $this->validatedPayload($request);

        try {
            $sector = $this->sectors->create($name, $rating, $description);
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e->getMessage());
        } catch (Throwable $e) {
            Log::error('Failed to create sector.', ['error' => $e->getMessage()]);

            return $this->unavailable();
        }

        return response()->json(['sector' => $sector], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        [$name, $rating, $description] = $this->validatedPayload($request);

        try {
            $sector = $this->sectors->update($id, $name, $rating, $description);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e->getMessage());
        } catch (Throwable $e) {
            Log::error('Failed to update sector.', ['error' => $e->getMessage()]);

            return $this->unavailable();
        }

        return response()->json(['sector' => $sector]);
    }

    public function destroy(int $id): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $this->sectors->delete($id);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (Throwable $e) {
            Log::error('Failed to delete sector.', ['error' => $e->getMessage()]);

            return $this->unavailable();
        }

        return response()->noContent();
    }

    /**
     * Validate the shared name/rating/description payload; contract-verbatim
     * 422 messages.
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private function validatedPayload(Request $request): array
    {
        $validator = Validator::make($request->json()->all(), [
            'name' => ['required', 'string', 'max:100'],
            'rating' => ['required', 'integer', 'between:0,100'],
            'description' => ['required', 'string', 'max:500'],
        ], [
            'name.required' => 'Name is required.',
            'name.string' => 'Name is required.',
            'name.max' => 'Name must be 100 characters or fewer.',
            'rating.required' => 'Rating must be an integer between 0 and 100.',
            'rating.integer' => 'Rating must be an integer between 0 and 100.',
            'rating.between' => 'Rating must be an integer between 0 and 100.',
            'description.required' => 'A description is required.',
            'description.string' => 'A description is required.',
            'description.max' => 'Description must be 500 characters or fewer.',
        ]);

        if ($validator->fails()) {
            $this->abort422($validator->errors()->first());
        }

        $name = trim((string) $request->json('name'));
        $description = trim((string) $request->json('description'));

        if ($name === '') {
            $this->abort422('Name is required.');
        }

        if ($description === '') {
            $this->abort422('A description is required.');
        }

        return [$name, (int) $request->json('rating'), $description];
    }

    private function abort422(string $message): never
    {
        $this->invalid($message)->throwResponse();
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['detail' => $message], 422);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['detail' => 'Sector not found.'], 404);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['detail' => 'Catalog store unavailable.'], 503);
    }
}