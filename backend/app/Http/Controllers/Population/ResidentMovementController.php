<?php

namespace App\Http\Controllers\Population;

use App\Http\Resources\Population\ResidentMovementResource;
use App\Http\Resources\Population\ResidentResource;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * FR-04: mutasi warga. Setiap perubahan keluarga/alamat atau status kependudukan menambah baris riwayat
 * (append-only) lalu memperbarui keadaan terkini warga — riwayat tidak pernah ditimpa.
 */
class ResidentMovementController extends PopulationController
{
    public function index(Request $request, Resident $resident): AnonymousResourceCollection
    {
        $this->authorizeRead($request, $resident->household?->unit);

        return ResidentMovementResource::collection(
            $resident->movements()->with('recorder:id,display_name')->orderByDesc('effective_date')->orderByDesc('created_at')->get(),
        );
    }

    public function store(Request $request, Resident $resident): JsonResponse
    {
        $fromHousehold = $resident->household;
        $this->authorizeWrite($request, $fromHousehold?->unit);

        $data = $request->validate([
            'movement_type' => ['required', Rule::in(ResidentMovement::TYPES)],
            'effective_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'required_if:movement_type,'.ResidentMovement::CORRECTION, 'string', 'max:500'],
            'to_household_id' => [
                Rule::requiredIf(in_array($request->input('movement_type'), [ResidentMovement::MOVE_IN, ResidentMovement::MOVE_HOUSE], true)),
                'nullable', 'uuid',
            ],
            'residency_status' => [
                Rule::requiredIf($request->input('movement_type') === ResidentMovement::CORRECTION),
                'nullable',
                Rule::in([...Resident::RESIDENCY_STATUSES, Resident::STATUS_MOVED_OUT, Resident::STATUS_DECEASED]),
            ],
        ]);

        $type = $data['movement_type'];
        $toHousehold = null;
        if (in_array($type, [ResidentMovement::MOVE_IN, ResidentMovement::MOVE_HOUSE], true)) {
            $toHousehold = Household::query()->find($data['to_household_id']);
            abort_if($toHousehold === null, 422, 'Keluarga tujuan tidak ditemukan.');
            // Tujuan di RT lain: penulis harus berwenang di kedua unit.
            $this->authorizeWrite($request, $toHousehold->unit);
            abort_if($toHousehold->status !== 'active', 422, 'Keluarga tujuan tidak aktif.');
        }

        $movement = DB::transaction(function () use ($resident, $fromHousehold, $toHousehold, $data, $type, $request) {
            $resident = Resident::query()->lockForUpdate()->findOrFail($resident->id);
            $this->apply($resident, $type, $toHousehold, $data);

            $movement = ResidentMovement::query()->create([
                'resident_id' => $resident->id,
                'movement_type' => $type,
                'from_household_id' => $fromHousehold->id,
                'to_household_id' => $toHousehold?->id ?? ($type === ResidentMovement::CORRECTION ? $fromHousehold->id : null),
                'effective_date' => $data['effective_date'],
                'reason' => $data['reason'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            $this->audit->log('resident.movement_recorded', 'resident_movement', $movement->id, $fromHousehold->unit_id, [
                'resident_id' => $resident->id,
                'movement_type' => $type,
                'from_household_id' => $movement->from_household_id,
                'to_household_id' => $movement->to_household_id,
                'to_unit_id' => $toHousehold?->unit_id,
                'effective_date' => $data['effective_date'],
                'residency_status' => $resident->residency_status,
            ]);

            return $movement;
        });

        return response()->json([
            'data' => new ResidentMovementResource($movement),
            'resident' => new ResidentResource($resident->refresh()->load('household')),
        ], 201);
    }

    /** @param  array<string, mixed>  $data */
    private function apply(Resident $resident, string $type, ?Household $toHousehold, array $data): void
    {
        $date = $data['effective_date'];
        abort_if(! $resident->is_alive && $type !== ResidentMovement::CORRECTION, 409, 'Warga berstatus meninggal; hanya koreksi yang dapat dicatat.');

        switch ($type) {
            case ResidentMovement::MOVE_IN:
                abort_if($resident->residency_status !== Resident::STATUS_MOVED_OUT, 409, 'Warga masih tercatat sebagai penduduk aktif.');
                $resident->fill([
                    'household_id' => $toHousehold->id,
                    'residency_status' => in_array($data['residency_status'] ?? null, Resident::RESIDENCY_STATUSES, true) ? $data['residency_status'] : 'permanent',
                    'moved_in_at' => $date,
                    'moved_out_at' => null,
                    'is_household_head' => false,
                ]);
                break;

            case ResidentMovement::MOVE_OUT:
                abort_if(! $resident->isActiveMember(), 409, 'Warga sudah tidak aktif.');
                $resident->releaseHeadship();
                $resident->fill(['residency_status' => Resident::STATUS_MOVED_OUT, 'moved_out_at' => $date]);
                break;

            case ResidentMovement::MOVE_HOUSE:
                abort_if(! $resident->isActiveMember(), 409, 'Warga sudah tidak aktif.');
                abort_if($toHousehold->id === $resident->household_id, 422, 'Keluarga tujuan sama dengan keluarga saat ini.');
                $resident->releaseHeadship();
                $resident->household_id = $toHousehold->id;
                break;

            case ResidentMovement::DEATH:
                $resident->releaseHeadship();
                $resident->fill(['is_alive' => false, 'residency_status' => Resident::STATUS_DECEASED]);
                break;

            case ResidentMovement::CORRECTION:
                $status = $data['residency_status'];
                if ($status === Resident::STATUS_DECEASED || $status === Resident::STATUS_MOVED_OUT) {
                    $resident->releaseHeadship();
                }
                $resident->fill([
                    'residency_status' => $status,
                    'is_alive' => $status !== Resident::STATUS_DECEASED,
                    'moved_out_at' => $status === Resident::STATUS_MOVED_OUT ? ($resident->moved_out_at ?? $date) : null,
                ]);
                break;
        }

        $resident->save();
    }
}
