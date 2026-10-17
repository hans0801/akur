<?php

namespace App\Services\Finance;

use App\Models\DuesAssessment;
use App\Models\Household;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Alokasi pembayaran ke tagihan dan efeknya. Semua metode dipanggil di dalam transaksi DB.
 * Pembayaran baru hanya "memesan" kapasitas tagihan; amount_paid baru bertambah saat diverifikasi bendahara.
 */
class PaymentProcessor
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Rencana alokasi: tagihan terbuka keluarga dikunci, dikurangi alokasi pembayaran lain yang belum diverifikasi.
     * Tanpa `$requested`, diisi dari tagihan paling lama jatuh tempo.
     *
     * @param  array<int, array{dues_assessment_id: string, amount: int}>|null  $requested
     * @return array<string, int> dues_assessment_id => amount
     */
    public function plan(Household $household, int $amount, ?array $requested): array
    {
        $assessments = DuesAssessment::query()
            ->where('household_id', $household->id)
            ->whereIn('status', DuesAssessment::OPEN_STATUSES)
            ->orderBy('due_on')->orderBy('created_at')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $pending = PaymentAllocation::query()
            ->whereIn('dues_assessment_id', $assessments->keys())
            ->whereHas('payment', fn ($q) => $q->where('status', Payment::SUBMITTED))
            ->selectRaw('dues_assessment_id, sum(amount) AS total')
            ->groupBy('dues_assessment_id')
            ->pluck('total', 'dues_assessment_id');

        $capacity = $assessments->map(fn (DuesAssessment $a) => $a->outstanding() - (int) ($pending[$a->id] ?? 0));

        if ($requested !== null && $requested !== []) {
            $plan = [];
            foreach ($requested as $i => $row) {
                $id = $row['dues_assessment_id'];
                if (! $capacity->has($id) || $row['amount'] > $capacity[$id]) {
                    throw ValidationException::withMessages(["allocations.{$i}.amount" => 'Melebihi sisa tagihan atau tagihan tidak tersedia.']);
                }
                $plan[$id] = ($plan[$id] ?? 0) + $row['amount'];
            }
            if (array_sum($plan) !== $amount) {
                throw ValidationException::withMessages(['allocations' => 'Jumlah alokasi harus sama dengan nominal pembayaran.']);
            }

            return $plan;
        }

        $plan = [];
        $remaining = $amount;
        foreach ($capacity as $id => $free) {
            if ($remaining === 0) {
                break;
            }
            if ($free > 0) {
                $plan[$id] = min($free, $remaining);
                $remaining -= $plan[$id];
            }
        }
        if ($remaining > 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal melebihi total tagihan yang belum dibayar.']);
        }

        return $plan;
    }

    /** @param  array<string, int>  $plan */
    public function attach(Payment $payment, array $plan): void
    {
        foreach ($plan as $assessmentId => $amount) {
            $payment->allocations()->create(['dues_assessment_id' => $assessmentId, 'amount' => $amount]);
        }
    }

    /** Verifikasi: tambah amount_paid, perbarui status tagihan, catat pemasukan kas per unit pemilik iuran. */
    public function verify(Payment $payment, User $actor): void
    {
        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
        abort_if($payment->status !== Payment::SUBMITTED, 409, 'Pembayaran ini sudah diproses.');

        $allocations = $payment->allocations()->with('assessment.period.duesType')->get();
        $byUnit = [];
        foreach ($allocations as $allocation) {
            $assessment = DuesAssessment::query()->lockForUpdate()->findOrFail($allocation->dues_assessment_id);
            abort_if($assessment->status === DuesAssessment::WAIVED, 409, 'Salah satu tagihan sudah dibebaskan; tolak pembayaran ini.');
            abort_if($assessment->amount_paid + $allocation->amount > $assessment->amount_due, 409, 'Pembayaran melebihi sisa tagihan.');

            $assessment->amount_paid += $allocation->amount;
            $assessment->refreshStatus();
            $assessment->save();

            $type = $allocation->assessment->period->duesType;
            $byUnit[$type->unit_id][] = [$type->name, $allocation->assessment->period->period_code, $allocation->amount];
        }

        $payment->fill(['status' => Payment::VERIFIED, 'verified_by' => $actor->id, 'verified_at' => now()])->save();

        foreach ($byUnit as $unitId => $rows) {
            $labels = implode(', ', array_unique(array_map(fn ($r) => "{$r[0]} {$r[1]}", $rows)));
            $this->ledger->record(
                $unitId,
                $this->ledger->duesIncomeCategory($unitId),
                array_sum(array_column($rows, 2)),
                $payment->paid_at ?? now(),
                "Iuran: {$labels}",
                $actor,
                'payment',
                $payment->id,
            );
        }
    }

    public function reject(Payment $payment, User $actor, string $reason): void
    {
        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
        abort_if($payment->status !== Payment::SUBMITTED, 409, 'Pembayaran ini sudah diproses.');

        $payment->fill(['status' => Payment::REJECTED, 'verified_by' => $actor->id, 'verified_at' => now(), 'rejection_reason' => $reason])->save();
    }
}
