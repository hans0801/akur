<?php

namespace Tests\Feature\Finance;

use App\Models\AdministrativeUnit;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\DuesAssessment;
use App\Models\DuesPeriod;
use App\Models\DuesType;
use App\Models\FinanceTransaction;
use App\Models\Household;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class DuesPaymentTest extends TestCase
{
    public function test_bendahara_creates_period_and_assessments_for_active_households(): void
    {
        ['rt1' => $rt, 'rt2' => $rt2] = $this->unitTree();
        $bendahara = $this->bearerFor($this->userWithRole($rt, Role::BENDAHARA));
        [$warga] = $this->linkedWarga($rt);
        $this->residentIn($rt);
        Household::query()->create(['unit_id' => $rt->id, 'address_text' => 'Pindah', 'status' => 'moved_out']);
        $this->residentIn($rt2);

        $typeId = $this->postJson("/api/v1/units/{$rt->id}/dues-types", [
            'name' => 'Iuran kebersihan', 'default_amount' => 25000, 'frequency' => 'monthly',
        ], $bendahara)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/dues-types/{$typeId}/periods", [
            'period_code' => '2026-10', 'starts_on' => '2026-10-01', 'due_on' => '2026-10-10',
        ], $bendahara)->assertCreated()->assertJsonPath('data.assessments_count', 2);

        $this->postJson("/api/v1/dues-types/{$typeId}/periods", [
            'period_code' => '2026-10', 'starts_on' => '2026-10-01', 'due_on' => '2026-10-10',
        ], $bendahara)->assertJsonValidationErrors('period_code');

        $this->assertTrue(AppNotification::query()->where('user_id', $warga->id)->where('type', 'dues.assessed')->exists());
        $this->getJson('/api/v1/my/dues', $this->bearerFor($warga))
            ->assertOk()
            ->assertJsonPath('data.outstanding_total', 25000)
            ->assertJsonPath('data.assessments.0.status', 'unpaid');
    }

    public function test_warga_payment_is_pending_until_verified_then_posts_income(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $bendaharaUser = $this->userWithRole($rt, Role::BENDAHARA);
        $bendahara = $this->bearerFor($bendaharaUser);
        [$warga, $household] = $this->linkedWarga($rt);
        $this->period($rt, $bendaharaUser, '2026-09', 20000);
        $this->period($rt, $bendaharaUser, '2026-10', 20000);
        $headers = $this->bearerFor($warga) + ['Idempotency-Key' => 'abc-1'];

        $paymentId = $this->postJson('/api/v1/payments', ['amount' => 30000, 'payment_method' => 'transfer', 'reference_code' => 'TRX123'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonCount(2, 'data.allocations')
            ->json('data.id');

        // Retry dengan kunci yang sama tidak membuat pembayaran baru.
        $this->postJson('/api/v1/payments', ['amount' => 30000, 'payment_method' => 'transfer'], $headers)->assertOk()->assertJsonPath('data.id', $paymentId);
        $this->assertSame(1, Payment::query()->count());

        // Belum diverifikasi: tagihan tetap belum terbayar.
        $this->assertSame(0, (int) DuesAssessment::query()->sum('amount_paid'));

        $this->postJson("/api/v1/payments/{$paymentId}/verify", [], $bendahara)->assertOk()->assertJsonPath('data.status', 'verified');
        $this->postJson("/api/v1/payments/{$paymentId}/verify", [], $bendahara)->assertConflict();

        $statuses = DuesAssessment::query()->where('household_id', $household->id)->orderBy('due_on')->pluck('status')->all();
        $this->assertSame(['paid', 'partial'], $statuses);

        $income = FinanceTransaction::query()->sole();
        $this->assertSame(['income', 30000, 'payment', $paymentId], [$income->kind, $income->amount, $income->reference_type, $income->reference_id]);
        $this->assertTrue(AppNotification::query()->where('user_id', $warga->id)->where('type', 'payment.verified')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'payment.verified')->exists());
    }

    public function test_cannot_overpay_or_double_book_capacity(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        [$warga] = $this->linkedWarga($rt);
        $this->period($rt, $bendahara, '2026-10', 20000);
        $headers = $this->bearerFor($warga);

        $this->postJson('/api/v1/payments', ['amount' => 25000, 'payment_method' => 'cash'], $headers)->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/payments', ['amount' => 15000, 'payment_method' => 'cash'], $headers)->assertCreated();
        // Sisa kapasitas 5.000 karena 15.000 sudah dipesan pembayaran yang menunggu verifikasi.
        $this->postJson('/api/v1/payments', ['amount' => 10000, 'payment_method' => 'cash'], $headers)->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/payments', ['amount' => 5000, 'payment_method' => 'cash'], $headers)->assertCreated();
    }

    public function test_rejected_payment_frees_capacity_and_notifies(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        [$warga] = $this->linkedWarga($rt);
        $this->period($rt, $bendahara, '2026-10', 20000);
        $headers = $this->bearerFor($warga);
        $id = $this->postJson('/api/v1/payments', ['amount' => 20000, 'payment_method' => 'transfer'], $headers)->json('data.id');

        $this->postJson("/api/v1/payments/{$id}/reject", [], $this->bearerFor($bendahara))->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/payments/{$id}/reject", ['reason' => 'Bukti transfer tidak ditemukan'], $this->bearerFor($bendahara))
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertTrue(AppNotification::query()->where('user_id', $warga->id)->where('type', 'payment.rejected')->exists());
        $this->assertSame(0, FinanceTransaction::query()->count());
        $this->postJson('/api/v1/payments', ['amount' => 20000, 'payment_method' => 'transfer'], $headers)->assertCreated();
    }

    public function test_bendahara_records_cash_payment_verified_immediately(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        [, $household] = $this->linkedWarga($rt);
        $this->period($rt, $bendahara, '2026-10', 20000);

        $this->postJson("/api/v1/units/{$rt->id}/payments", [
            'household_id' => $household->id, 'amount' => 20000, 'payment_method' => 'cash',
        ], $this->bearerFor($bendahara))->assertCreated()->assertJsonPath('data.status', 'verified');

        $this->assertSame('paid', DuesAssessment::query()->sole()->status);
        $this->assertSame(20000, FinanceTransaction::query()->sole()->amount);
    }

    public function test_waive_requires_reason_and_no_pending_payment(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $bendaharaUser = $this->userWithRole($rt, Role::BENDAHARA);
        $bendahara = $this->bearerFor($bendaharaUser);
        [$warga] = $this->linkedWarga($rt);
        $this->period($rt, $bendaharaUser, '2026-10', 20000);
        $assessment = DuesAssessment::query()->sole();
        $paymentId = $this->postJson('/api/v1/payments', ['amount' => 5000, 'payment_method' => 'cash'], $this->bearerFor($warga))->json('data.id');

        $this->postJson("/api/v1/dues-assessments/{$assessment->id}/waive", ['reason' => 'Kurang mampu'], $bendahara)->assertConflict();
        $this->postJson("/api/v1/payments/{$paymentId}/reject", ['reason' => 'Batal'], $bendahara)->assertOk();
        $this->postJson("/api/v1/dues-assessments/{$assessment->id}/waive", ['reason' => 'Kurang mampu'], $bendahara)
            ->assertOk()->assertJsonPath('data.status', 'waived')->assertJsonPath('data.outstanding', 0);
        $this->getJson('/api/v1/my/dues', $this->bearerFor($warga))->assertJsonPath('data.outstanding_total', 0);
    }

    public function test_roles_and_cross_rt_isolation(): void
    {
        ['rw' => $rw, 'rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $bendahara1 = $this->userWithRole($rt1, Role::BENDAHARA);
        [$warga1] = $this->linkedWarga($rt1);
        [, $household2] = $this->linkedWarga($rt2);
        $period = $this->period($rt1, $bendahara1, '2026-10', 20000);
        $type = $period->duesType;
        $paymentId = $this->postJson('/api/v1/payments', ['amount' => 20000, 'payment_method' => 'cash'], $this->bearerFor($warga1))->json('data.id');

        $bendahara2 = $this->bearerFor($this->userWithRole($rt2, Role::BENDAHARA));
        $this->getJson("/api/v1/dues-periods/{$period->id}", $bendahara2)->assertForbidden();
        $this->postJson("/api/v1/payments/{$paymentId}/verify", [], $bendahara2)->assertForbidden();
        $this->postJson("/api/v1/units/{$rt1->id}/payments", ['household_id' => $household2->id, 'amount' => 1, 'payment_method' => 'cash'], $this->bearerFor($bendahara1))
            ->assertStatus(422);

        foreach ([Role::KETUA_RT, Role::SEKRETARIS, Role::WARGA] as $role) {
            $headers = $this->bearerFor($this->userWithRole($rt1, $role));
            $this->postJson("/api/v1/dues-types/{$type->id}/periods", ['period_code' => 'X', 'starts_on' => '2026-11-01', 'due_on' => '2026-11-10'], $headers)->assertForbidden();
            $this->postJson("/api/v1/payments/{$paymentId}/verify", [], $headers)->assertForbidden();
        }

        // Ketua RT dan ketua RW boleh melihat rincian, sekretaris dan warga tidak.
        $this->getJson("/api/v1/dues-periods/{$period->id}", $this->bearerFor($this->userWithRole($rt1, Role::KETUA_RT)))->assertOk();
        $this->getJson("/api/v1/units/{$rw->id}/payments", $this->bearerFor($this->userWithRole($rw, Role::KETUA_RW)))->assertOk()->assertJsonPath('data.0.id', $paymentId);
        $this->getJson("/api/v1/dues-periods/{$period->id}", $this->bearerFor($this->userWithRole($rt1, Role::SEKRETARIS)))->assertForbidden();
        $this->getJson("/api/v1/units/{$rt1->id}/payments", $this->bearerFor($warga1))->assertForbidden();

        $this->assertSame('submitted', Payment::query()->sole()->status);
    }

    public function test_treasurer_cannot_verify_own_payment(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        [$bendahara] = $this->linkedWarga($rt);
        $this->grant($bendahara, $rt, Role::BENDAHARA);
        $this->period($rt, $bendahara, '2026-10', 20000);
        $id = $this->postJson('/api/v1/payments', ['amount' => 20000, 'payment_method' => 'cash'], $this->bearerFor($bendahara))->json('data.id');

        $this->postJson("/api/v1/payments/{$id}/verify", [], $this->bearerFor($bendahara))->assertForbidden();
    }

    public function test_reconcile_command_detects_and_fixes_drift(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        $this->linkedWarga($rt);
        $this->period($rt, $bendahara, '2026-10', 20000);
        DuesAssessment::query()->update(['amount_paid' => 5000, 'status' => 'partial']);

        $this->artisan('akur:reconcile-dues')->assertFailed();
        $this->artisan('akur:reconcile-dues --fix')->assertSuccessful();
        $this->assertSame([0, 'unpaid'], [DuesAssessment::query()->sole()->amount_paid, DuesAssessment::query()->sole()->status]);
        $this->artisan('akur:reconcile-dues')->assertSuccessful();
    }

    /** @return array{0: User, 1: Household} */
    private function linkedWarga(AdministrativeUnit $unit): array
    {
        $user = $this->userWithRole($unit, Role::WARGA);
        $resident = $this->residentIn($unit, ['user_id' => $user->id]);

        return [$user, $resident->household];
    }

    private function period(AdministrativeUnit $unit, User $bendahara, string $code, int $amount)
    {
        $type = DuesType::query()->firstOrCreate(
            ['unit_id' => $unit->id, 'name' => 'Iuran bulanan'],
            ['default_amount' => $amount, 'frequency' => 'monthly', 'created_by' => $bendahara->id],
        );
        if (! $this->isGranted($bendahara, $unit)) {
            $this->grant($bendahara, $unit, Role::BENDAHARA);
        }
        $id = $this->postJson("/api/v1/dues-types/{$type->id}/periods", [
            'period_code' => $code, 'starts_on' => "{$code}-01", 'due_on' => "{$code}-10",
        ], $this->bearerFor($bendahara))->assertCreated()->json('data.id');

        return DuesPeriod::query()->with('duesType')->findOrFail($id);
    }

    private function isGranted(User $user, AdministrativeUnit $unit): bool
    {
        return $user->memberships()->where('unit_id', $unit->id)->where('role_id', Role::idFor(Role::BENDAHARA))->exists();
    }
}
