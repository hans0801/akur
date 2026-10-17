<?php

namespace App\Http\Controllers\Population;

use App\Models\AdministrativeUnit;
use App\Models\House;
use App\Models\Street;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** FR-04: rumah per jalan. Cakupan unit mengikuti jalannya. */
class HouseController extends PopulationController
{
    public function index(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeRead($request, $unit);

        $houses = House::query()
            ->join('streets', 'streets.id', '=', 'houses.street_id')
            ->whereIn('streets.unit_id', $unit->selfAndDescendantIds())
            ->when($request->query('street_id'), fn ($q, $streetId) => $q->where('houses.street_id', $streetId))
            ->orderBy('streets.name')
            ->orderByRaw('length(houses.house_number), houses.house_number')
            ->select(['houses.id', 'houses.street_id', 'houses.house_number', 'houses.block_label', 'houses.occupancy_status', 'streets.name as street_name', 'streets.unit_id'])
            ->paginate(50);

        return response()->json($houses);
    }

    public function store(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeWrite($request, $unit);
        $data = $request->validate([
            'street_id' => ['required', 'uuid'],
            ...$this->rules(),
        ]);

        $street = Street::query()->find($data['street_id']);
        if ($street === null || $street->unit_id !== $unit->id) {
            throw ValidationException::withMessages(['street_id' => 'Jalan tidak ditemukan di unit ini.']);
        }

        $house = $this->saveUnique(new House($data));
        $this->audit->log('house.created', 'house', $house->id, $unit->id, ['street_id' => $street->id, 'house_number' => $house->house_number]);

        return response()->json(['data' => $house], 201);
    }

    public function update(Request $request, House $house): JsonResponse
    {
        $unit = $house->street->unit;
        $this->authorizeWrite($request, $unit);
        $data = $request->validate(array_map(fn ($rules) => ['sometimes', ...$rules], $this->rules()));

        $house->fill($data);
        $changes = self::dirtyChanges($house);
        $this->saveUnique($house);

        if ($changes !== []) {
            $this->audit->log('house.updated', 'house', $house->id, $unit->id, ['changes' => $changes]);
        }

        return response()->json(['data' => $house]);
    }

    public function destroy(Request $request, House $house): JsonResponse
    {
        $unit = $house->street->unit;
        $this->authorizeWrite($request, $unit);
        abort_if($house->households()->exists(), 409, 'Rumah masih dipakai oleh data keluarga.');

        $house->delete();
        $this->audit->log('house.deleted', 'house', $house->id, $unit->id, ['house_number' => $house->house_number]);

        return response()->json(['message' => 'Rumah dihapus.']);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'house_number' => ['required', 'string', 'max:30'],
            'block_label' => ['nullable', 'string', 'max:30'],
            'occupancy_status' => ['required', Rule::in(House::OCCUPANCY_STATUSES)],
        ];
    }

    private function saveUnique(House $house): House
    {
        try {
            // Savepoint agar transaksi luar (jika ada) tetap bisa dipakai setelah pelanggaran unik.
            DB::transaction(fn () => $house->save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['house_number' => 'Nomor rumah dengan blok yang sama sudah ada di jalan ini.']);
        }

        return $house;
    }
}
