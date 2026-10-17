<?php

namespace App\Http\Controllers;

use App\Models\AdministrativeUnit;
use App\Models\Resident;
use App\Services\Access\UnitAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencarian ringkas data warga untuk proses penautan akun. Tidak mengembalikan NIK/KK.
 * CRUD warga/keluarga lengkap dijadwalkan di W5.
 */
class UnitResidentController extends Controller
{
    public function index(Request $request, AdministrativeUnit $unit, UnitAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $unit, config('akur.linking_roles'));

        $unitIds = $unit->unit_type === AdministrativeUnit::TYPE_RW
            ? [$unit->id, ...$unit->children()->pluck('id')->all()]
            : [$unit->id];

        $q = trim((string) $request->query('q', ''));

        $residents = Resident::query()
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->whereIn('households.unit_id', $unitIds)
            ->when($q !== '', fn ($query) => $query->where('residents.full_name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->when($request->boolean('unlinked'), fn ($query) => $query->whereNull('residents.user_id'))
            ->where('residents.is_alive', true)
            ->orderBy('residents.full_name')
            ->select([
                'residents.id',
                'residents.full_name',
                'residents.is_household_head',
                'residents.household_id',
                'households.address_text',
                'households.unit_id',
            ])
            ->selectRaw('residents.user_id IS NOT NULL AS is_linked')
            ->paginate(20);

        return response()->json($residents);
    }
}
