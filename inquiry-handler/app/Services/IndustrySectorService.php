<?php

namespace App\Services;

use App\Models\IndustrySector;
use InvalidArgumentException;

/**
 * CRUD + read access for the industry-sector catalog (feature 012,
 * contracts/sectors-admin.md, data-model.md).
 *
 * The catalog is small and uncached: the factor reads the live map on every
 * triage run so dashboard edits are immediately visible (FR-008, contract
 * Notes) — no cache invalidation step exists.
 *
 * Name uniqueness is enforced case/whitespace-insensitively here as a guard on
 * top of the controller's validation (contract 422 path). DB-layer failures are
 * deliberately NOT wrapped: they propagate as Throwable so the controller can
 * map them to a 503 "Catalog store unavailable." (contract Errors).
 */
class IndustrySectorService
{
    /**
     * @return array<int, IndustrySector>  ordered rating desc, then name asc
     */
    public function all(): array
    {
        return IndustrySector::query()
            ->orderByDesc('rating')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @param  array<string, int>  mapped name → rating, for the scoring factor
     */
    public function activeMap(): array
    {
        $map = [];

        foreach ($this->all() as $sector) {
            $map[$sector->name] = $sector->rating;
        }

        return $map;
    }

    /**
     * @return array<string, string>  mapped name → description, for the factor's
     *                                classifier prompt (empty string when unset)
     */
    public function activeDescriptions(): array
    {
        $map = [];

        foreach ($this->all() as $sector) {
            $map[$sector->name] = trim((string) $sector->description);
        }

        return $map;
    }

    public function create(string $name, int $rating, string $description): IndustrySector
    {
        $this->assertNameAvailable(trim($name), null);

        return IndustrySector::query()->create([
            'name' => trim($name),
            'rating' => $rating,
            'description' => trim($description),
        ]);
    }

    public function update(int $id, string $name, int $rating, string $description): IndustrySector
    {
        $sector = IndustrySector::query()->findOrFail($id);

        $this->assertNameAvailable(trim($name), $sector->id);

        $sector->update([
            'name' => trim($name),
            'rating' => $rating,
            'description' => trim($description),
        ]);

        return $sector->refresh();
    }

    public function delete(int $id): void
    {
        IndustrySector::query()->findOrFail($id)->delete();
    }

    /**
     * Case/whitespace-insensitive uniqueness check. `$ignoreId` is the row being
     * updated so a sector may keep (or be renamed back to) its own name.
     *
     * @throws InvalidArgumentException when a case-insensitive duplicate exists
     */
    private function assertNameAvailable(string $name, ?int $ignoreId): void
    {
        if ($name === '') {
            return;
        }

        $existing = IndustrySector::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($existing) {
            throw new InvalidArgumentException('A sector with that name already exists.');
        }
    }
}