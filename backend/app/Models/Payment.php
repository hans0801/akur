<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasUuids;

    public const SUBMITTED = 'submitted';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public const REFUNDED = 'refunded';

    public const METHODS = ['cash', 'transfer', 'qris', 'ewallet'];

    protected $fillable = [
        'payer_user_id', 'household_id', 'amount', 'payment_method', 'status', 'paid_at', 'reference_code',
        'verified_by', 'verified_at', 'rejection_reason', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
