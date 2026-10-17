<?php

namespace App\Http\Controllers\Population;

use App\Models\AdministrativeUnit;
use App\Models\Street;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-04: master jalan per unit. Jalan tidak dihapus, hanya dinonaktifkan. */
class StreetController extends PopulationController
{
    public function index(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeRead($request, $unit);

        $streets = Street::query()
            ->whereIn('unit_id', $unit->selfAndDescendantIds())
            ->unless($request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->withCount('houses')
            ->orderBy('name')
            ->get(['id', 'unit_id', 'name', 'is_active']);

        return response()->json(['data' => $streets]);
    }

    public function store(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeWrite($request, $unit);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $street = Street::query()->create(['unit_id' => $unit->id, 'name' => $data['name'], 'is_active' => true]);
        $this->audit->log('street.created', 'street', $street->id, $unit->id, ['name' => $street->name]);

        return response()->json(['data' => $street], 201);
    }

    public function update(Request $request, Street $street): JsonResponse
    {
        $this->authorizeWrite($request, $street->unit);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $street->fill($data);
        $changes = self::dirtyChanges($street);
        $street->save();

        if ($changes !== []) {
            $this->audit->log('street.updated', 'street', $street->id, $street->unit_id, ['changes' => $changes]);
        }

        return response()->json(['data' => $street]);
    }
}
