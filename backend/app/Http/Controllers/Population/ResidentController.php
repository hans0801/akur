<?php

namespace App\Http\Controllers\Population;

use App\Http\Resources\Population\ResidentResource;
use App\Models\AdministrativeUnit;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentMovement;
use App\Services\Pii\PopulationPii;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FR-04: data warga. NIK terenkripsi, selalu dimasking; reveal/lookup diaudit.
 * Alamat (keluarga) dan status kependudukan hanya berubah lewat mutasi (ResidentMovementController).
 */
class ResidentController extends PopulationController
{
    private const STATUS_FILTERS = ['active', 'all', Resident::STATUS_MOVED_OUT, Resident::STATUS_DECEASED];

    /** Juga dipakai alur penautan akun (FR-02): `unlinked=1` dan `q`. */
    public function index(Request $request, AdministrativeUnit $unit): AnonymousResourceCollection
    {
        $this->authorizeRead($request, $unit);

        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), self::STATUS_FILTERS, true) ? $request->query('status') : 'active';

        $residents = Resident::query()
            ->whereHas('household', fn ($h) => $h->whereIn('unit_id', $unit->selfAndDescendantIds()))
            ->when($q !== '', fn ($query) => $query->where('full_name', 'ilike', self::like($q)))
            ->when($request->query('household_id'), fn ($query, $id) => $query->where('household_id', $id))
            ->when($request->boolean('unlinked'), fn ($query) => $query->whereNull('user_id'))
            ->when($status === 'active', fn ($query) => $query->where('is_alive', true)->where('residency_status', '!=', Resident::STATUS_MOVED_OUT))
            ->when($status === Resident::STATUS_MOVED_OUT, fn ($query) => $query->where('residency_status', Resident::STATUS_MOVED_OUT))
            ->when($status === Resident::STATUS_DECEASED, fn ($query) => $query->where('is_alive', false))
            ->with('household:id,unit_id,address_text')
            ->orderBy('full_name')
            ->paginate(20);

        return ResidentResource::collection($residents);
    }

    public function show(Request $request, Resident $resident): ResidentResource
    {
        $this->authorizeRead($request, $resident->household?->unit);

        return new ResidentResource($resident->load(['movements' => fn ($m) => $m->with('recorder:id,display_name')->orderByDesc('effective_date')->orderByDesc('created_at')]));
    }

    /** Warga baru di sebuah keluarga; tercatat sebagai mutasi `move_in`. */
    public function store(Request $request, Household $household): JsonResponse
    {
        $unit = $household->unit;
        $this->authorizeWrite($request, $unit);
        abort_if($household->status !== 'active', 422, 'Keluarga tidak aktif.');

        $data = $request->validate([
            ...$this->biodataRules(),
            'full_name' => ['required', 'string', 'max:160'],
            'residency_status' => ['required', Rule::in(Resident::RESIDENCY_STATUSES)],
            'is_household_head' => ['sometimes', 'boolean'],
            'moved_in_at' => ['nullable', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $resident = DB::transaction(function () use ($data, $household, $unit, $request) {
            $resident = new Resident([
                ...collect($data)->only(['full_name', 'birth_place', 'birth_date', 'gender', 'marital_status', 'occupation', 'education', 'residency_status', 'moved_in_at'])->all(),
                'household_id' => $household->id,
                'is_alive' => true,
                'is_household_head' => false,
            ]);
            if (! empty($data['nik'])) {
                $this->ensureNikAvailable($data['nik']);
                $resident->forceFill($this->pii->columns($data['nik'], PopulationPii::NIK, 'nik'));
            }
            $resident->save();

            if ($data['is_household_head'] ?? false) {
                Resident::query()->where('household_id', $household->id)->update(['is_household_head' => false]);
                $resident->forceFill(['is_household_head' => true])->save();
                $household->update(['head_resident_id' => $resident->id]);
            }

            $movement = ResidentMovement::query()->create([
                'resident_id' => $resident->id,
                'movement_type' => ResidentMovement::MOVE_IN,
                'to_household_id' => $household->id,
                'effective_date' => $data['moved_in_at'] ?? now()->toDateString(),
                'reason' => $data['reason'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            $this->audit->log('resident.created', 'resident', $resident->id, $unit->id, [
                'household_id' => $household->id,
                'movement_id' => $movement->id,
                'pii_fields_set' => empty($data['nik']) ? [] : ['nik'],
            ]);

            return $resident;
        });

        return (new ResidentResource($resident->refresh()->load('household')))->response()->setStatusCode(201);
    }

    /** Hanya biodata. Keluarga/status/tanggal pindah ditolak — gunakan mutasi. */
    public function update(Request $request, Resident $resident): ResidentResource
    {
        $unit = $resident->household?->unit;
        $this->authorizeWrite($request, $unit);

        $prohibited = 'Perubahan alamat/status dicatat lewat mutasi warga.';
        $data = $request->validate([
            ...$this->biodataRules(),
            'full_name' => ['sometimes', 'string', 'max:160'],
            'reason' => ['nullable', 'string', 'max:500'],
            ...array_fill_keys(['household_id', 'residency_status', 'is_alive', 'moved_in_at', 'moved_out_at', 'is_household_head', 'user_id'], ['prohibited']),
        ], ['prohibited' => $prohibited]);

        DB::transaction(function () use ($resident, $data, $unit) {
            $resident->fill(collect($data)->only(['full_name', 'birth_place', 'birth_date', 'gender', 'marital_status', 'occupation', 'education'])->all());
            $changes = self::dirtyChanges($resident);

            $piiChanged = [];
            if (array_key_exists('nik', $data)) {
                if (! empty($data['nik'])) {
                    $this->ensureNikAvailable($data['nik'], $resident->id);
                }
                $resident->forceFill($this->pii->columns($data['nik'], PopulationPii::NIK, 'nik'));
                $piiChanged[] = 'nik';
            }
            $resident->save();

            if ($changes !== [] || $piiChanged !== []) {
                $this->audit->log('resident.updated', 'resident', $resident->id, $unit->id, [
                    'changes' => $changes,
                    'pii_fields_changed' => $piiChanged,
                    'reason' => $data['reason'] ?? null,
                ]);
            }
        });

        return new ResidentResource($resident->refresh()->load('household'));
    }

    /** Hapus (soft delete) hanya untuk data yang salah input. Warga pindah/meninggal dicatat lewat mutasi. */
    public function destroy(Request $request, Resident $resident): JsonResponse
    {
        $unit = $resident->household?->unit;
        $this->authorizeWrite($request, $unit);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        abort_if($resident->user_id !== null, 409, 'Data warga terhubung ke akun; tidak dapat dihapus.');

        DB::transaction(function () use ($resident, $unit, $data) {
            // NIK salah input dilepas agar bisa dipakai di data yang benar (lookup HMAC unik).
            $resident->releaseHeadship();
            $resident->forceFill($this->pii->columns(null, PopulationPii::NIK, 'nik'))->save();
            $resident->delete();
            $this->audit->log('resident.deleted', 'resident', $resident->id, $unit->id, ['reason' => $data['reason']]);
        });

        return response()->json(['message' => 'Data warga dihapus.']);
    }

    /** Menampilkan NIK utuh — hanya pengurus penulis, wajib alasan, selalu diaudit. */
    public function revealNik(Request $request, Resident $resident): JsonResponse
    {
        $this->authorizeWrite($request, $resident->household?->unit);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $nik = $this->pii->reveal($resident->getAttributes()['nik_ciphertext'] ?? null, PopulationPii::NIK);
        abort_if($nik === null, 404, 'NIK belum diisi.');

        $this->audit->log('resident.nik_revealed', 'resident', $resident->id, $resident->household->unit_id, ['reason' => $data['reason']]);

        return response()->json(['data' => ['nik' => $nik]])->header('Cache-Control', 'no-store');
    }

    /** Pencarian exact-match NIK di cakupan unit (POST agar NIK tidak masuk URL/log akses). */
    public function lookupNik(Request $request, AdministrativeUnit $unit): AnonymousResourceCollection
    {
        $this->authorizeWrite($request, $unit);
        $data = $request->validate(['nik' => ['required', 'string', 'regex:/^\d{16}$/']]);

        $residents = $this->pii->whereLookup(Resident::query(), 'nik_lookup_hmac', $data['nik'], PopulationPii::NIK)
            ->whereHas('household', fn ($h) => $h->whereIn('unit_id', $unit->selfAndDescendantIds()))
            ->with('household:id,unit_id,address_text')
            ->get();

        $this->audit->log('resident.nik_lookup', 'administrative_unit', $unit->id, $unit->id, ['found' => $residents->isNotEmpty()]);

        return ResidentResource::collection($residents);
    }

    /** @return array<string, list<mixed>> */
    private function biodataRules(): array
    {
        return [
            'nik' => ['sometimes', 'nullable', 'string', 'regex:/^\d{16}$/'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:120'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'gender' => ['sometimes', 'nullable', Rule::in(Resident::GENDERS)],
            'marital_status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:120'],
            'education' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }

    /** Pesan tidak menyebut di unit mana NIK itu terdaftar. */
    private function ensureNikAvailable(string $nik, ?string $exceptId = null): void
    {
        $taken = $this->pii->whereLookup(Resident::withTrashed(), 'nik_lookup_hmac', $nik, PopulationPii::NIK)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['nik' => 'NIK sudah terdaftar.']);
        }
    }
}
