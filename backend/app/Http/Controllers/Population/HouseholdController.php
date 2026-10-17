<?php

namespace App\Http\Controllers\Population;

use App\Http\Resources\Population\HouseholdResource;
use App\Models\AdministrativeUnit;
use App\Models\House;
use App\Models\Household;
use App\Models\Resident;
use App\Models\Street;
use App\Services\Pii\PopulationPii;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FR-04: keluarga (KK). No. KK terenkripsi + HMAC lookup, selalu dimasking; reveal diaudit.
 * Perpindahan anggota keluarga dicatat lewat mutasi warga, bukan dengan mengubah data di sini.
 */
class HouseholdController extends PopulationController
{
    public function index(Request $request, AdministrativeUnit $unit): AnonymousResourceCollection
    {
        $this->authorizeRead($request, $unit);

        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status', 'active');

        $households = Household::query()
            ->whereIn('unit_id', $unit->selfAndDescendantIds())
            ->when(in_array($status, Household::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('address_text', 'ilike', self::like($q))
                    ->orWhere('household_number', 'ilike', self::like($q))
                    ->orWhereHas('head', fn ($h) => $h->where('full_name', 'ilike', self::like($q)));
            }))
            ->with(['head:id,full_name', 'street:id,name', 'house:id,house_number'])
            ->withCount(['residents' => fn ($r) => $r->where('is_alive', true)->where('residency_status', '!=', Resident::STATUS_MOVED_OUT)])
            ->orderBy('address_text')
            ->paginate(20);

        return HouseholdResource::collection($households);
    }

    public function show(Request $request, Household $household): HouseholdResource
    {
        $this->authorizeRead($request, $household->unit);

        return new HouseholdResource($household->load([
            'head:id,full_name', 'street:id,name', 'house:id,house_number',
            'residents' => fn ($r) => $r->with('household')->orderByDesc('is_household_head')->orderBy('full_name'),
        ]));
    }

    public function store(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeWrite($request, $unit);
        $data = $request->validate([
            'household_number' => ['nullable', 'string', 'max:40'],
            'kk_number' => ['nullable', 'string', 'regex:/^\d{16}$/'],
            'street_id' => ['nullable', 'uuid'],
            'house_id' => ['nullable', 'uuid'],
            'address_text' => ['required', 'string', 'max:500'],
        ]);

        $household = DB::transaction(function () use ($data, $unit) {
            $household = new Household([
                'unit_id' => $unit->id,
                'household_number' => $data['household_number'] ?? null,
                'address_text' => $data['address_text'],
                'status' => 'active',
                ...$this->placement($data, $unit),
            ]);

            if (! empty($data['kk_number'])) {
                $this->ensureKkAvailable($data['kk_number']);
                $household->forceFill($this->pii->columns($data['kk_number'], PopulationPii::KK, 'kk_number'));
            }
            $household->save();

            $this->audit->log('household.created', 'household', $household->id, $unit->id, [
                'household_number' => $household->household_number,
                'street_id' => $household->street_id,
                'house_id' => $household->house_id,
                'pii_fields_set' => empty($data['kk_number']) ? [] : ['kk_number'],
            ]);

            return $household;
        });

        return (new HouseholdResource($household->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Household $household): HouseholdResource
    {
        $unit = $household->unit;
        $this->authorizeWrite($request, $unit);
        $data = $request->validate([
            'household_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'kk_number' => ['sometimes', 'nullable', 'string', 'regex:/^\d{16}$/'],
            'street_id' => ['sometimes', 'nullable', 'uuid'],
            'house_id' => ['sometimes', 'nullable', 'uuid'],
            'address_text' => ['sometimes', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(Household::STATUSES)],
            'head_resident_id' => ['sometimes', 'nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($household, $data, $unit) {
            $household->fill(collect($data)->only(['household_number', 'address_text', 'status'])->all());
            if (array_key_exists('street_id', $data) || array_key_exists('house_id', $data)) {
                $household->fill($this->placement($data + $household->only(['street_id', 'house_id']), $unit));
            }
            if (array_key_exists('head_resident_id', $data)) {
                $this->assignHead($household, $data['head_resident_id']);
            }

            // Perubahan alamat/status keluarga disimpan lengkap (lama → baru) di audit log; riwayat warga ada di mutasi.
            $changes = self::dirtyChanges($household);
            $piiChanged = [];
            if (array_key_exists('kk_number', $data)) {
                if (! empty($data['kk_number'])) {
                    $this->ensureKkAvailable($data['kk_number'], $household->id);
                }
                $household->forceFill($this->pii->columns($data['kk_number'], PopulationPii::KK, 'kk_number'));
                $piiChanged[] = 'kk_number';
            }
            $household->save();

            if ($changes !== [] || $piiChanged !== []) {
                $this->audit->log('household.updated', 'household', $household->id, $unit->id, [
                    'changes' => $changes,
                    'pii_fields_changed' => $piiChanged,
                    'reason' => $data['reason'] ?? null,
                ]);
            }
        });

        return new HouseholdResource($household->refresh()->load(['head:id,full_name', 'street:id,name', 'house:id,house_number']));
    }

    /** Menampilkan No. KK utuh — hanya pengurus penulis, wajib alasan, selalu diaudit. */
    public function revealKk(Request $request, Household $household): JsonResponse
    {
        $this->authorizeWrite($request, $household->unit);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $kk = $this->pii->reveal($household->getAttributes()['kk_number_ciphertext'] ?? null, PopulationPii::KK);
        abort_if($kk === null, 404, 'No. KK belum diisi.');

        $this->audit->log('household.kk_revealed', 'household', $household->id, $household->unit_id, ['reason' => $data['reason']]);

        return response()->json(['data' => ['kk_number' => $kk]])->header('Cache-Control', 'no-store');
    }

    /**
     * Jalan/rumah harus milik unit keluarga. Jika hanya rumah diberikan, jalan mengikuti rumah.
     *
     * @param  array<string, mixed>  $data
     * @return array{street_id: ?string, house_id: ?string}
     */
    private function placement(array $data, AdministrativeUnit $unit): array
    {
        $house = empty($data['house_id']) ? null : House::query()->with('street')->find($data['house_id']);
        if (! empty($data['house_id']) && ($house === null || $house->street->unit_id !== $unit->id)) {
            throw ValidationException::withMessages(['house_id' => 'Rumah tidak ditemukan di unit ini.']);
        }

        $streetId = $data['street_id'] ?? $house?->street_id;
        if ($streetId !== null && ! Street::query()->whereKey($streetId)->where('unit_id', $unit->id)->exists()) {
            throw ValidationException::withMessages(['street_id' => 'Jalan tidak ditemukan di unit ini.']);
        }
        if ($house !== null && $house->street_id !== $streetId) {
            throw ValidationException::withMessages(['house_id' => 'Rumah tidak berada di jalan yang dipilih.']);
        }

        return ['street_id' => $streetId, 'house_id' => $house?->id];
    }

    private function assignHead(Household $household, ?string $residentId): void
    {
        if ($residentId !== null) {
            $isMember = Resident::query()
                ->whereKey($residentId)
                ->where('household_id', $household->id)
                ->where('is_alive', true)
                ->where('residency_status', '!=', Resident::STATUS_MOVED_OUT)
                ->exists();
            if (! $isMember) {
                throw ValidationException::withMessages(['head_resident_id' => 'Kepala keluarga harus anggota aktif keluarga ini.']);
            }
        }

        Resident::query()->where('household_id', $household->id)->update(['is_household_head' => false]);
        if ($residentId !== null) {
            Resident::query()->whereKey($residentId)->update(['is_household_head' => true]);
        }
        $household->head_resident_id = $residentId;
    }

    private function ensureKkAvailable(string $kk, ?string $exceptId = null): void
    {
        $taken = $this->pii->whereLookup(Household::query(), 'kk_number_lookup_hmac', $kk, PopulationPii::KK)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['kk_number' => 'No. KK sudah terdaftar.']);
        }
    }
}
