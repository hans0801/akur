<?php

namespace Tests\Feature\Finance;

use App\Models\AdministrativeUnit;
use App\Models\AuditLog;
use App\Models\DuesAssessment;
use App\Models\DuesType;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Role;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class CashbookTest extends TestCase
{
    public function test_bendahara_records_income_and_expense_and_balance_follows(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::BENDAHARA));
        $categories = collect($this->getJson("/api/v1/units/{$rt->id}/finance-categories", $headers)->assertOk()->json('data'));
        $donasi = $categories->firstWhere('name', 'Donasi')['id'];
        $kebersihan = $categories->firstWhere('name', 'Kebersihan')['id'];

        $this->postJson("/api/v1/units/{$rt->id}/finance-transactions", [
            'category_id' => $donasi, 'amount' => 500000, 'occurred_at' => now()->subDay()->toIso8601String(), 'description' => 'Donasi warga',
        ], $headers)->assertCreated()->assertJsonPath('data.kind', 'income');
        $this->postJson("/api/v1/units/{$rt->id}/finance-transactions", [
            'category_id' => $kebersihan, 'amount' => 150000, 'occurred_at' => now()->toIso8601String(), 'description' => 'Beli sapu',
        ], $headers)->assertCreated()->assertJsonPath('data.kind', 'expense');

        $this->getJson("/api/v1/units/{$rt->id}/finance-transactions", $headers)->assertOk()->assertJsonPath('balance', 350000)->assertJsonCount(2, 'data');
        $this->assertSame(2, AuditLog::query()->where('action', 'finance_transaction.created')->count());
    }

    public function test_reversal_corrects_balance_once_and_rows_are_immutable(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::BENDAHARA));
        $category = $this->category($rt, 'expense', 'Keamanan');
        $id = $this->postJson("/api/v1/units/{$rt->id}/finance-transactions", [
            'category_id' => $category->id, 'amount' => 200000, 'occurred_at' => now()->toIso8601String(), 'description' => 'Honor satpam (salah input)',
        ], $headers)->json('data.id');

        $this->postJson("/api/v1/finance-transactions/{$id}/reverse", [], $headers)->assertJsonValidationErrors('reason');
        $reversalId = $this->postJson("/api/v1/finance-transactions/{$id}/reverse", ['reason' => 'Nominal salah'], $headers)
            ->assertCreated()->assertJsonPath('data.kind', 'reversal')->json('data.id');
        $this->postJson("/api/v1/finance-transactions/{$id}/reverse", ['reason' => 'Lagi'], $headers)->assertConflict();
        $this->postJson("/api/v1/finance-transactions/{$reversalId}/reverse", ['reason' => 'Balik pembalik'], $headers)->assertUnprocessable();

        $this->getJson("/api/v1/units/{$rt->id}/finance-transactions", $headers)->assertJsonPath('balance', 0);

        $this->expectException(QueryException::class);
        FinanceTransaction::query()->whereKey($id)->update(['amount' => 1]);
    }

    public function test_monthly_report_is_aggregate_and_visible_to_members(): void
    {
        ['rt1' => $rt, 'rt2' => $rt2] = $this->unitTree();
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        $headers = $this->bearerFor($bendahara);
        $income = $this->category($rt, 'income', 'Donasi');
        $expense = $this->category($rt, 'expense', 'Kegiatan warga');
        $lastMonth = now('Asia/Jakarta')->subMonthNoOverflow()->startOfMonth()->addDays(2);
        // Awal bulan berjalan (bukan masa depan), agar tetap valid berapa pun tanggal tes dijalankan.
        $thisMonth = now('Asia/Jakarta')->startOfMonth()->addSecond();

        $this->tx($rt, $headers, $income, 100000, $lastMonth);
        $this->tx($rt, $headers, $income, 300000, $thisMonth);
        $this->tx($rt, $headers, $expense, 50000, $thisMonth);

        $warga = $this->bearerFor($this->userWithRole($rt, Role::WARGA));
        $report = $this->getJson("/api/v1/units/{$rt->id}/finance-reports/monthly?month=".$thisMonth->format('Y-m'), $warga)
            ->assertOk()
            ->assertJsonPath('data.opening_balance', 100000)
            ->assertJsonPath('data.total_income', 300000)
            ->assertJsonPath('data.total_expense', 50000)
            ->assertJsonPath('data.closing_balance', 350000)
            ->json('data');
        $this->assertArrayNotHasKey('transactions', $report);

        $this->getJson("/api/v1/units/{$rt->id}/finance-reports/yearly?year=".$thisMonth->year, $warga)
            ->assertOk()
            ->assertJsonPath('data.months.'.($thisMonth->month - 1).'.closing_balance', 350000);

        // Warga melihat agregat, tetapi tidak rincian transaksi; warga RT lain tidak melihat apa pun.
        $this->getJson("/api/v1/units/{$rt->id}/finance-transactions", $warga)->assertForbidden();
        $other = $this->bearerFor($this->userWithRole($rt2, Role::WARGA));
        $this->getJson("/api/v1/units/{$rt->id}/finance-reports/monthly", $other)->assertForbidden();
    }

    public function test_refund_reverses_income_and_reopens_dues(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        $headers = $this->bearerFor($bendahara);
        $household = $this->residentIn($rt)->household;
        $type = DuesType::query()->create(['unit_id' => $rt->id, 'name' => 'Iuran', 'default_amount' => 20000, 'frequency' => 'monthly', 'created_by' => $bendahara->id]);
        $this->postJson("/api/v1/dues-types/{$type->id}/periods", ['period_code' => '2026-10', 'starts_on' => '2026-10-01', 'due_on' => '2026-10-10'], $headers)->assertCreated();
        $paymentId = $this->postJson("/api/v1/units/{$rt->id}/payments", ['household_id' => $household->id, 'amount' => 20000, 'payment_method' => 'cash'], $headers)->json('data.id');

        $incomeId = FinanceTransaction::query()->sole()->id;
        $this->postJson("/api/v1/finance-transactions/{$incomeId}/reverse", ['reason' => 'x'], $headers)->assertUnprocessable();

        $this->postJson("/api/v1/payments/{$paymentId}/refund", ['reason' => 'Uang palsu'], $headers)->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->postJson("/api/v1/payments/{$paymentId}/refund", ['reason' => 'Lagi'], $headers)->assertConflict();

        $this->assertSame([0, 'unpaid'], [DuesAssessment::query()->sole()->amount_paid, DuesAssessment::query()->sole()->status]);
        $this->getJson("/api/v1/units/{$rt->id}/finance-transactions", $headers)->assertJsonPath('balance', 0);
        $this->artisan('akur:reconcile-dues')->assertSuccessful();
    }

    public function test_only_treasurer_writes_and_categories_are_unit_scoped(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $foreignCategory = $this->category($rt2, 'income', 'Donasi');
        $tx = fn (string $categoryId) => ['category_id' => $categoryId, 'amount' => 1000, 'occurred_at' => now()->toIso8601String(), 'description' => 'X'];

        $bendahara1 = $this->bearerFor($this->userWithRole($rt1, Role::BENDAHARA));
        $this->postJson("/api/v1/units/{$rt1->id}/finance-transactions", $tx($foreignCategory->id), $bendahara1)->assertUnprocessable();
        $this->postJson("/api/v1/units/{$rt2->id}/finance-transactions", $tx($foreignCategory->id), $bendahara1)->assertForbidden();

        foreach ([Role::KETUA_RT, Role::SEKRETARIS, Role::WARGA] as $role) {
            $headers = $this->bearerFor($this->userWithRole($rt2, $role));
            $this->postJson("/api/v1/units/{$rt2->id}/finance-transactions", $tx($foreignCategory->id), $headers)->assertForbidden();
            $this->postJson("/api/v1/units/{$rt2->id}/finance-categories", ['name' => 'Baru', 'kind' => 'income'], $headers)->assertForbidden();
        }
        $this->getJson("/api/v1/units/{$rt2->id}/finance-transactions", $this->bearerFor($this->userWithRole($rt2, Role::KETUA_RT)))->assertOk();
        $this->assertSame(0, FinanceTransaction::query()->count());
    }

    public function test_dues_income_category_cannot_be_renamed(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::BENDAHARA));
        $this->getJson("/api/v1/units/{$rt->id}/finance-categories", $headers)->assertOk();
        $dues = FinanceCategory::query()->where('unit_id', $rt->id)->where('name', FinanceCategory::DUES_INCOME_NAME)->sole();

        $this->patchJson("/api/v1/finance-categories/{$dues->id}", ['name' => 'Lain'], $headers)->assertUnprocessable();
        $this->patchJson("/api/v1/finance-categories/{$dues->id}", ['is_active' => true], $headers)->assertOk();
    }

    private function category(AdministrativeUnit $unit, string $kind, string $name): FinanceCategory
    {
        return FinanceCategory::query()->create(['unit_id' => $unit->id, 'kind' => $kind, 'name' => $name, 'is_active' => true]);
    }

    private function tx(AdministrativeUnit $unit, array $headers, FinanceCategory $category, int $amount, $at): void
    {
        $this->postJson("/api/v1/units/{$unit->id}/finance-transactions", [
            'category_id' => $category->id, 'amount' => $amount, 'occurred_at' => $at->toIso8601String(), 'description' => 'Uji',
        ], $headers)->assertCreated();
    }
}
