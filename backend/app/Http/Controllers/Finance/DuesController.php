<?php

namespace App\Http\Controllers\Finance;

use App\Models\AdministrativeUnit;
use App\Models\DuesAssessment;
use App\Models\DuesPeriod;
use App\Models\DuesType;
use App\Models\Household;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * W8: jenis iuran, periode tagihan, dan tagihan per keluarga.
 * Membuat periode langsung menerbitkan tagihan untuk semua keluarga aktif di unit (RW: termasuk RT di bawahnya).
 */
class DuesController extends FinanceController
{
    public function types(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeRead($request, $unit);

        $types = DuesType::query()
            ->whereIn('unit_id', $unit->selfAndAncestorIds())
            ->with('unit:id,name')
            ->withCount('periods')
            ->orderByDesc('is_active')->orderBy('name')
            ->get();

        return response()->json(['data' => $types]);
    }

    public function storeType(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeWrite($request, $unit);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_amount' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'frequency' => ['required', Rule::in(DuesType::FREQUENCIES)],
        ]);

        $type = DuesType::query()->create($data + ['unit_id' => $unit->id, 'is_active' => true, 'created_by' => $request->user()->id]);
        $this->audit->log('dues_type.created', 'dues_type', $type->id, $unit->id, collect($data)->except('description')->all());

        return response()->json(['data' => $type], 201);
    }

    /** Mengubah nominal bawaan tidak mengubah tagihan yang sudah terbit. */
    public function updateType(Request $request, DuesType $duesType): JsonResponse
    {
        $this->authorizeWrite($request, $duesType->unit);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_amount' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $duesType->fill($data);
        $changes = [];
        foreach (array_keys($duesType->getDirty()) as $key) {
            $changes[$key] = [$duesType->getOriginal($key), $duesType->getAttribute($key)];
        }
        $duesType->save();
        if ($changes !== []) {
            $this->audit->log('dues_type.updated', 'dues_type', $duesType->id, $duesType->unit_id, ['changes' => $changes]);
        }

        return response()->json(['data' => $duesType]);
    }

    public function periods(Request $request, DuesType $duesType): JsonResponse
    {
        $this->authorizeRead($request, $duesType->unit);

        $periods = $duesType->periods()
            ->withCount('assessments')
            ->withSum('assessments as total_due', 'amount_due')
            ->withSum('assessments as total_paid', 'amount_paid')
            ->orderByDesc('starts_on')
            ->get();

        return response()->json(['data' => $periods]);
    }

    public function storePeriod(Request $request, DuesType $duesType): JsonResponse
    {
        $unit = $duesType->unit;
        $this->authorizeWrite($request, $unit);
        abort_if(! $duesType->is_active, 422, 'Jenis iuran tidak aktif.');
        $data = $request->validate([
            'period_code' => ['required', 'string', 'max:30', Rule::unique('dues_periods')->where('dues_type_id', $duesType->id)],
            'starts_on' => ['required', 'date'],
            'due_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);
        $amount = $data['amount'] ?? $duesType->default_amount;

        [$period, $householdIds] = DB::transaction(function () use ($duesType, $unit, $data, $amount) {
            $period = DuesPeriod::query()->create([
                'dues_type_id' => $duesType->id,
                'period_code' => $data['period_code'],
                'starts_on' => $data['starts_on'],
                'due_on' => $data['due_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'status' => DuesPeriod::OPEN,
            ]);

            $householdIds = Household::query()
                ->whereIn('unit_id', $unit->selfAndDescendantIds())
                ->where('status', 'active')
                ->pluck('id')
                ->all();
            $now = now();
            DuesAssessment::query()->insert(array_map(fn ($householdId) => [
                'id' => (string) str()->uuid(),
                'dues_period_id' => $period->id,
                'household_id' => $householdId,
                'amount_due' => $amount,
                'amount_paid' => 0,
                'status' => $amount === 0 ? DuesAssessment::PAID : DuesAssessment::UNPAID,
                'due_on' => $data['due_on'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $householdIds));

            $this->audit->log('dues_period.created', 'dues_period', $period->id, $unit->id, [
                'dues_type_id' => $duesType->id,
                'period_code' => $period->period_code,
                'amount' => $amount,
                'assessments' => count($householdIds),
            ]);

            return [$period, $householdIds];
        });

        $this->notifyHouseholds($householdIds, 'dues.assessed', 'Tagihan iuran baru', "{$duesType->name} {$period->period_code}", [
            'dues_period_id' => $period->id,
        ]);

        return response()->json(['data' => $period->loadCount('assessments')], 201);
    }

    /** Rincian periode: ringkasan dan tagihan per keluarga (tanpa NIK/KK). */
    public function showPeriod(Request $request, DuesPeriod $duesPeriod): JsonResponse
    {
        $duesPeriod->load('duesType.unit');
        $this->authorizeRead($request, $duesPeriod->duesType->unit);
        $status = $request->query('status');

        $assessments = $duesPeriod->assessments()
            ->with(['household:id,unit_id,address_text,household_number,head_resident_id', 'household.head:id,full_name', 'household.unit:id,name'])
            ->when(in_array($status, [DuesAssessment::UNPAID, DuesAssessment::PARTIAL, DuesAssessment::PAID, DuesAssessment::WAIVED], true), fn ($q) => $q->where('status', $status))
            ->get()
            ->sortBy(fn (DuesAssessment $a) => $a->household->address_text)
            ->values();

        return response()->json(['data' => [
            'period' => $duesPeriod,
            'summary' => [
                'households' => $duesPeriod->assessments()->count(),
                'total_due' => (int) $duesPeriod->assessments()->where('status', '!=', DuesAssessment::WAIVED)->sum('amount_due'),
                'total_paid' => (int) $duesPeriod->assessments()->sum('amount_paid'),
                'paid_count' => $duesPeriod->assessments()->where('status', DuesAssessment::PAID)->count(),
            ],
            'assessments' => $assessments->map(fn (DuesAssessment $a) => self::presentAssessment($a) + [
                'household' => [
                    'id' => $a->household->id,
                    'address_text' => $a->household->address_text,
                    'household_number' => $a->household->household_number,
                    'head_name' => $a->household->head?->full_name,
                    'unit_name' => $a->household->unit?->name,
                ],
            ]),
        ]]);
    }

    public function closePeriod(Request $request, DuesPeriod $duesPeriod): JsonResponse
    {
        $unit = $duesPeriod->duesType->unit;
        $this->authorizeWrite($request, $unit);
        abort_if($duesPeriod->status === DuesPeriod::CLOSED, 409, 'Periode sudah ditutup.');

        $duesPeriod->update(['status' => DuesPeriod::CLOSED]);
        $this->audit->log('dues_period.closed', 'dues_period', $duesPeriod->id, $unit->id);

        return response()->json(['data' => $duesPeriod]);
    }

    /** Bebaskan sisa tagihan (mis. warga kurang mampu). Pembayaran yang belum diverifikasi harus ditolak dulu. */
    public function waive(Request $request, DuesAssessment $duesAssessment): JsonResponse
    {
        $unit = $duesAssessment->period->duesType->unit;
        $this->authorizeWrite($request, $unit);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        abort_if(! in_array($duesAssessment->status, DuesAssessment::OPEN_STATUSES, true), 409, 'Tagihan ini sudah lunas atau dibebaskan.');
        abort_if(
            $duesAssessment->allocations()->whereHas('payment', fn ($q) => $q->where('status', Payment::SUBMITTED))->exists(),
            409,
            'Masih ada pembayaran yang menunggu verifikasi untuk tagihan ini.',
        );

        DB::transaction(function () use ($duesAssessment, $unit, $data) {
            $duesAssessment->update(['status' => DuesAssessment::WAIVED]);
            $this->audit->log('dues_assessment.waived', 'dues_assessment', $duesAssessment->id, $unit->id, [
                'household_id' => $duesAssessment->household_id,
                'outstanding' => $duesAssessment->amount_due - $duesAssessment->amount_paid,
                'reason' => $data['reason'],
            ]);
        });

        return response()->json(['data' => self::presentAssessment($duesAssessment->refresh())]);
    }

    /** @return array<string, mixed> */
    public static function presentAssessment(DuesAssessment $a): array
    {
        return [
            'id' => $a->id,
            'dues_period_id' => $a->dues_period_id,
            'household_id' => $a->household_id,
            'amount_due' => $a->amount_due,
            'amount_paid' => $a->amount_paid,
            'outstanding' => $a->outstanding(),
            'status' => $a->status,
            'is_overdue' => $a->isOverdue(),
            'due_on' => $a->due_on->toDateString(),
        ];
    }
}
