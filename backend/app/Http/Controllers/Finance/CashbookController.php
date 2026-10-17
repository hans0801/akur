<?php

namespace App\Http\Controllers\Finance;

use App\Models\AdministrativeUnit;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Services\Finance\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * W9: buku kas per unit. Bendahara mencatat pemasukan/pengeluaran manual dan membalik transaksi yang salah;
 * transaksi tidak pernah diubah atau dihapus (dijaga trigger DB).
 */
class CashbookController extends FinanceController
{
    public function categories(Request $request, AdministrativeUnit $unit, Ledger $ledger): JsonResponse
    {
        $this->authorizeRead($request, $unit);
        if ($this->access->hasAnyRole($request->user(), $unit, config('akur.finance_write_roles'))) {
            $ledger->ensureDefaultCategories($unit);
        }

        return response()->json(['data' => FinanceCategory::query()
            ->where('unit_id', $unit->id)
            ->orderBy('kind')->orderBy('name')
            ->get(['id', 'unit_id', 'name', 'kind', 'is_active'])]);
    }

    public function storeCategory(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeWrite($request, $unit);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('finance_categories')->where('unit_id', $unit->id)->where('kind', $request->input('kind'))],
            'kind' => ['required', Rule::in([FinanceCategory::INCOME, FinanceCategory::EXPENSE])],
        ]);

        $category = FinanceCategory::query()->create($data + ['unit_id' => $unit->id, 'is_active' => true]);
        $this->audit->log('finance_category.created', 'finance_category', $category->id, $unit->id, $data);

        return response()->json(['data' => $category], 201);
    }

    public function updateCategory(Request $request, FinanceCategory $financeCategory): JsonResponse
    {
        $this->authorizeWrite($request, $financeCategory->unit);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('finance_categories')->where('unit_id', $financeCategory->unit_id)->where('kind', $financeCategory->kind)->ignore($financeCategory->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        abort_if(
            ($data['name'] ?? null) !== null && $financeCategory->name === FinanceCategory::DUES_INCOME_NAME,
            422,
            'Kategori iuran dipakai otomatis oleh pembayaran dan tidak dapat diganti namanya.',
        );

        $financeCategory->update($data);
        $this->audit->log('finance_category.updated', 'finance_category', $financeCategory->id, $financeCategory->unit_id, $data);

        return response()->json(['data' => $financeCategory]);
    }

    public function transactions(Request $request, AdministrativeUnit $unit, Ledger $ledger): JsonResponse
    {
        $this->authorizeRead($request, $unit);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'kind' => ['nullable', Rule::in([FinanceTransaction::INCOME, FinanceTransaction::EXPENSE, FinanceTransaction::REVERSAL])],
            'category_id' => ['nullable', 'uuid'],
        ]);

        $page = FinanceTransaction::query()
            ->where('unit_id', $unit->id)
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('occurred_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('occurred_at', '<', now()->parse($to)->addDay()))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->with(['category:id,name,kind', 'creator:id,display_name', 'reversal:id,reversed_transaction_id,created_at'])
            ->orderByDesc('occurred_at')->orderByDesc('created_at')
            ->paginate(30);
        $page->getCollection()->transform(fn (FinanceTransaction $t) => $this->present($t));

        return response()->json([...$page->toArray(), 'balance' => $ledger->balance([$unit->id])]);
    }

    public function store(Request $request, AdministrativeUnit $unit, Ledger $ledger): JsonResponse
    {
        $this->authorizeWrite($request, $unit);
        $data = $request->validate([
            'category_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'description' => ['required', 'string', 'max:500'],
        ]);
        $category = FinanceCategory::query()->whereKey($data['category_id'])->where('unit_id', $unit->id)->where('is_active', true)->first();
        abort_if($category === null, 422, 'Kategori tidak ditemukan di unit ini.');

        $transaction = DB::transaction(function () use ($request, $unit, $ledger, $data, $category) {
            $transaction = $ledger->record($unit->id, $category, $data['amount'], now()->parse($data['occurred_at']), $data['description'], $request->user());
            $this->audit->log('finance_transaction.created', 'finance_transaction', $transaction->id, $unit->id, [
                'kind' => $transaction->kind,
                'amount' => $transaction->amount,
                'category' => $category->name,
            ]);

            return $transaction;
        });

        return response()->json(['data' => $this->present($transaction->load(['category', 'creator']))], 201);
    }

    /** Koreksi transaksi yang salah: tambah pembalik, transaksi asal tetap ada. Pemasukan iuran dibalik lewat refund pembayaran. */
    public function reverse(Request $request, FinanceTransaction $financeTransaction, Ledger $ledger): JsonResponse
    {
        $unit = $financeTransaction->unit;
        $this->authorizeWrite($request, $unit);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        abort_if($financeTransaction->reference_type === 'payment', 422, 'Pemasukan dari pembayaran iuran dibatalkan lewat pengembalian (refund) pembayaran.');

        $reversal = DB::transaction(function () use ($request, $financeTransaction, $ledger, $data, $unit) {
            $locked = FinanceTransaction::query()->lockForUpdate()->findOrFail($financeTransaction->id);
            $reversal = $ledger->reverse($locked, $data['reason'], $request->user());
            $this->audit->log('finance_transaction.reversed', 'finance_transaction', $locked->id, $unit->id, [
                'reversal_id' => $reversal->id,
                'amount' => $locked->amount,
                'reason' => $data['reason'],
            ]);

            return $reversal;
        });

        return response()->json(['data' => $this->present($reversal->load(['category', 'creator']))], 201);
    }

    /** @return array<string, mixed> */
    private function present(FinanceTransaction $t): array
    {
        return [
            'id' => $t->id,
            'kind' => $t->kind,
            'amount' => $t->amount,
            'occurred_at' => $t->occurred_at->toIso8601String(),
            'description' => $t->description,
            'category' => $t->category ? ['id' => $t->category->id, 'name' => $t->category->name, 'kind' => $t->category->kind] : null,
            'reference_type' => $t->reference_type,
            'reversed_transaction_id' => $t->reversed_transaction_id,
            'is_reversed' => $t->relationLoaded('reversal') ? $t->reversal !== null : null,
            'created_by' => $t->creator?->display_name,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
