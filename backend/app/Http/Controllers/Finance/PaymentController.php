<?php

namespace App\Http\Controllers\Finance;

use App\Models\AdministrativeUnit;
use App\Models\DuesAssessment;
use App\Models\Household;
use App\Models\Payment;
use App\Models\UserMembership;
use App\Services\Finance\PaymentProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * W8: pembayaran iuran. Warga melaporkan pembayaran keluarganya (menunggu verifikasi bendahara);
 * bendahara mencatat pembayaran tunai (langsung terverifikasi), memverifikasi, atau menolak.
 * Header `Idempotency-Key` mencegah pembayaran ganda saat klien mengulang request.
 */
class PaymentController extends FinanceController
{
    public function myDues(Request $request): JsonResponse
    {
        $household = $this->ownHousehold($request->user());

        $assessments = DuesAssessment::query()
            ->where('household_id', $household->id)
            ->with('period.duesType:id,name,unit_id')
            ->orderByRaw("CASE WHEN status IN ('unpaid','partial') THEN 0 ELSE 1 END")
            ->orderByDesc('due_on')
            ->limit(100)
            ->get();

        return response()->json(['data' => [
            'household' => ['id' => $household->id, 'address_text' => $household->address_text],
            'outstanding_total' => $assessments->sum(fn (DuesAssessment $a) => $a->outstanding()),
            'assessments' => $assessments->map(fn (DuesAssessment $a) => DuesController::presentAssessment($a) + [
                'dues_type_name' => $a->period->duesType->name,
                'period_code' => $a->period->period_code,
            ]),
        ]]);
    }

    public function myPayments(Request $request): JsonResponse
    {
        $household = $this->ownHousehold($request->user());

        $page = Payment::query()
            ->where('household_id', $household->id)
            ->with('allocations.assessment.period.duesType:id,name')
            ->latest()
            ->paginate(20);
        $page->getCollection()->transform(fn (Payment $p) => $this->present($p));

        return response()->json($page);
    }

    /** Warga melaporkan pembayaran (transfer/QRIS/e-wallet/tunai ke bendahara) untuk keluarganya sendiri. */
    public function submit(Request $request, PaymentProcessor $processor): JsonResponse
    {
        $user = $request->user();
        $household = $this->ownHousehold($user);
        $data = $this->validatePayment($request);

        $key = $this->idempotencyKey($request);
        if ($key && ($existing = Payment::query()->where('idempotency_key', $key)->first())) {
            return response()->json(['data' => $this->present($existing->load('allocations.assessment.period.duesType'))]);
        }

        $payment = DB::transaction(function () use ($user, $household, $data, $key, $processor) {
            $plan = $processor->plan($household, $data['amount'], $data['allocations'] ?? null);
            $payment = Payment::query()->create([
                'payer_user_id' => $user->id,
                'household_id' => $household->id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'status' => Payment::SUBMITTED,
                'paid_at' => $data['paid_at'] ?? now(),
                'reference_code' => $data['reference_code'] ?? null,
                'idempotency_key' => $key,
            ]);
            $processor->attach($payment, $plan);

            $this->audit->log('payment.submitted', 'payment', $payment->id, $household->unit_id, [
                'household_id' => $household->id,
                'amount' => $payment->amount,
                'method' => $payment->payment_method,
            ]);

            return $payment;
        });

        $this->notifyTreasurers($household, 'payment.submitted', 'Pembayaran iuran menunggu verifikasi', 'Rp '.number_format($payment->amount, 0, ',', '.'), $payment->id);

        return response()->json(['data' => $this->present($payment->load('allocations.assessment.period.duesType'))], 201);
    }

    public function queue(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeRead($request, $unit);
        $status = $request->query('status', Payment::SUBMITTED);

        $page = Payment::query()
            ->whereHas('household', fn ($q) => $q->whereIn('unit_id', $unit->selfAndDescendantIds()))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['household:id,address_text,head_resident_id', 'household.head:id,full_name', 'payer:id,display_name', 'allocations.assessment.period.duesType:id,name'])
            ->latest()
            ->paginate(20);
        $page->getCollection()->transform(fn (Payment $p) => $this->present($p) + [
            'household' => ['id' => $p->household->id, 'address_text' => $p->household->address_text, 'head_name' => $p->household->head?->full_name],
            'payer_name' => $p->payer?->display_name,
        ]);

        return response()->json($page);
    }

    /** Bendahara mencatat pembayaran tunai yang diterima langsung; terverifikasi seketika. */
    public function recordCash(Request $request, AdministrativeUnit $unit, PaymentProcessor $processor): JsonResponse
    {
        $this->authorizeWrite($request, $unit);
        $data = $this->validatePayment($request) + $request->validate(['household_id' => ['required', 'uuid']]);
        $household = Household::query()->whereKey($data['household_id'])->whereIn('unit_id', $unit->selfAndDescendantIds())->first();
        abort_if($household === null, 422, 'Keluarga tidak ditemukan di unit ini.');

        $key = $this->idempotencyKey($request);
        if ($key && ($existing = Payment::query()->where('idempotency_key', $key)->first())) {
            return response()->json(['data' => $this->present($existing->load('allocations.assessment.period.duesType'))]);
        }

        $payment = DB::transaction(function () use ($request, $household, $data, $key, $processor) {
            $plan = $processor->plan($household, $data['amount'], $data['allocations'] ?? null);
            $payment = Payment::query()->create([
                'household_id' => $household->id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'status' => Payment::SUBMITTED,
                'paid_at' => $data['paid_at'] ?? now(),
                'reference_code' => $data['reference_code'] ?? null,
                'idempotency_key' => $key,
            ]);
            $processor->attach($payment, $plan);
            $processor->verify($payment, $request->user());

            $this->audit->log('payment.recorded', 'payment', $payment->id, $household->unit_id, [
                'household_id' => $household->id,
                'amount' => $payment->amount,
                'method' => $payment->payment_method,
            ]);

            return $payment;
        });

        $this->notifyHouseholds([$household->id], 'payment.verified', 'Pembayaran iuran diterima', 'Rp '.number_format($payment->amount, 0, ',', '.'), ['payment_id' => $payment->id]);

        return response()->json(['data' => $this->present($payment->refresh()->load('allocations.assessment.period.duesType'))], 201);
    }

    public function verify(Request $request, Payment $payment, PaymentProcessor $processor): JsonResponse
    {
        $this->authorizeWrite($request, $payment->household?->unit);
        abort_if($payment->payer_user_id === $request->user()->id, 403, 'Pembayaran Anda sendiri harus diverifikasi bendahara lain.');

        DB::transaction(function () use ($request, $payment, $processor) {
            $processor->verify($payment, $request->user());
            $this->audit->log('payment.verified', 'payment', $payment->id, $payment->household->unit_id, ['amount' => $payment->amount]);
        });
        $this->notifyPayer($payment, 'payment.verified', 'Pembayaran iuran diverifikasi');

        return response()->json(['data' => $this->present($payment->refresh()->load('allocations.assessment.period.duesType'))]);
    }

    public function reject(Request $request, Payment $payment, PaymentProcessor $processor): JsonResponse
    {
        $this->authorizeWrite($request, $payment->household?->unit);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        DB::transaction(function () use ($request, $payment, $processor, $data) {
            $processor->reject($payment, $request->user(), $data['reason']);
            $this->audit->log('payment.rejected', 'payment', $payment->id, $payment->household->unit_id, ['reason' => $data['reason']]);
        });
        $this->notifyPayer($payment, 'payment.rejected', 'Pembayaran iuran ditolak');

        return response()->json(['data' => $this->present($payment->refresh()->load('allocations.assessment.period.duesType'))]);
    }

    /** @return array<string, mixed> */
    private function validatePayment(Request $request): array
    {
        return $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'payment_method' => ['required', Rule::in(Payment::METHODS)],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'reference_code' => ['nullable', 'string', 'max:100'],
            'allocations' => ['nullable', 'array', 'max:50'],
            'allocations.*.dues_assessment_id' => ['required', 'uuid'],
            'allocations.*.amount' => ['required', 'integer', 'min:1'],
        ]);
    }

    /** Kunci idempotensi dicakup per pengguna agar tidak bertabrakan antar akun. */
    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || $key === '') {
            return null;
        }
        abort_if(strlen($key) > 64, 422, 'Idempotency-Key terlalu panjang.');

        return $request->user()->id.':'.$key;
    }

    private function notifyPayer(Payment $payment, string $type, string $title): void
    {
        if ($payment->payer_user_id) {
            $this->notifier->send($payment->payer_user_id, $type, $title, 'Rp '.number_format($payment->amount, 0, ',', '.'), ['payment_id' => $payment->id]);
        }
    }

    private function notifyTreasurers(Household $household, string $type, string $title, string $body, string $paymentId): void
    {
        $unit = $household->unit;
        $userIds = UserMembership::query()
            ->effective()
            ->whereIn('unit_id', $unit->selfAndAncestorIds())
            ->whereHas('role', fn ($q) => $q->whereIn('code', config('akur.finance_write_roles')))
            ->distinct()
            ->pluck('user_id');
        foreach ($userIds as $userId) {
            $this->notifier->send($userId, $type, $title, "{$body} · {$unit->name}", ['payment_id' => $paymentId]);
        }
    }

    /** @return array<string, mixed> */
    private function present(Payment $p): array
    {
        return [
            'id' => $p->id,
            'household_id' => $p->household_id,
            'amount' => $p->amount,
            'payment_method' => $p->payment_method,
            'status' => $p->status,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'reference_code' => $p->reference_code,
            'verified_at' => $p->verified_at?->toIso8601String(),
            'rejection_reason' => $p->rejection_reason,
            'allocations' => $p->allocations->map(fn ($a) => [
                'dues_assessment_id' => $a->dues_assessment_id,
                'amount' => $a->amount,
                'label' => $a->assessment?->period ? "{$a->assessment->period->duesType->name} {$a->assessment->period->period_code}" : null,
            ]),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
