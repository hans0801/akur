<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ledger kas (append-only). Koreksi tidak mengubah baris, tetapi menambah transaksi `reversal`
 * yang menunjuk transaksi asal; saldo = Σ income − Σ expense − efek transaksi yang dibalik.
 */
class FinanceTransaction extends Model
{
    use HasUuids;

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const REVERSAL = 'reversal';

    protected $fillable = [
        'unit_id', 'category_id', 'kind', 'amount', 'occurred_at', 'description',
        'reference_type', 'reference_id', 'created_by', 'reversed_transaction_id', 'receipt_file_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'occurred_at' => 'datetime'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AdministrativeUnit::class, 'unit_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversed(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_transaction_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversed_transaction_id');
    }
}
