<?php

namespace App\Services\Finance;

use App\Models\AdministrativeUnit;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Buku kas per unit. Hanya menambah baris; koreksi = transaksi `reversal` (lihat reverse()).
 */
class Ledger
{
    /** Siapkan kategori bawaan unit (idempotent). */
    public function ensureDefaultCategories(AdministrativeUnit $unit): void
    {
        foreach (FinanceCategory::DEFAULTS as $kind => $names) {
            foreach ($names as $name) {
                FinanceCategory::query()->firstOrCreate(['unit_id' => $unit->id, 'kind' => $kind, 'name' => $name], ['is_active' => true]);
            }
        }
    }

    public function duesIncomeCategory(string $unitId): FinanceCategory
    {
        return FinanceCategory::query()->firstOrCreate(
            ['unit_id' => $unitId, 'kind' => FinanceCategory::INCOME, 'name' => FinanceCategory::DUES_INCOME_NAME],
            ['is_active' => true],
        );
    }

    public function record(
        string $unitId,
        FinanceCategory $category,
        int $amount,
        CarbonInterface $occurredAt,
        string $description,
        User $actor,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): FinanceTransaction {
        return FinanceTransaction::query()->create([
            'unit_id' => $unitId,
            'category_id' => $category->id,
            'kind' => $category->kind,
            'amount' => $amount,
            'occurred_at' => $occurredAt,
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $actor->id,
        ]);
    }

    /** Membalik transaksi sekali saja (indeks unik reversed_transaction_id mencegah pembalikan ganda). */
    public function reverse(FinanceTransaction $original, string $reason, User $actor): FinanceTransaction
    {
        abort_if($original->kind === FinanceTransaction::REVERSAL, 422, 'Transaksi pembalik tidak dapat dibalik lagi.');
        abort_if($original->reversal()->exists(), 409, 'Transaksi ini sudah dibalik.');

        return FinanceTransaction::query()->create([
            'unit_id' => $original->unit_id,
            'category_id' => $original->category_id,
            'kind' => FinanceTransaction::REVERSAL,
            'amount' => $original->amount,
            'occurred_at' => now(),
            'description' => "Pembalikan: {$reason}",
            'reference_type' => $original->reference_type,
            'reference_id' => $original->reference_id,
            'created_by' => $actor->id,
            'reversed_transaction_id' => $original->id,
        ]);
    }

    /**
     * Efek bertanda tiap transaksi: income +, expense −, reversal = kebalikan transaksi asal.
     * Dipakai untuk saldo dan laporan agar semua angka berasal dari satu rumus.
     */
    public static function signedAmountSql(string $alias = 'ft'): string
    {
        return "CASE {$alias}.kind
            WHEN 'income' THEN {$alias}.amount
            WHEN 'expense' THEN -{$alias}.amount
            ELSE (SELECT CASE o.kind WHEN 'income' THEN -{$alias}.amount ELSE {$alias}.amount END
                  FROM finance_transactions o WHERE o.id = {$alias}.reversed_transaction_id)
        END";
    }

    /** @param  list<string>  $unitIds */
    public function balance(array $unitIds, ?CarbonInterface $before = null): int
    {
        return (int) DB::table('finance_transactions as ft')
            ->whereIn('ft.unit_id', $unitIds)
            ->when($before, fn ($q) => $q->where('ft.occurred_at', '<', $before))
            ->sum(DB::raw(self::signedAmountSql()));
    }
}
