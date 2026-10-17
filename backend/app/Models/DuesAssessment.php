<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tagihan iuran satu keluarga untuk satu periode. `amount_paid` adalah cache dari alokasi pembayaran
 * terverifikasi (lihat akur:reconcile-dues). Status `overdue` tidak disimpan; dihitung dari due_on.
 */
class DuesAssessment extends Model
{
    use HasUuids;

    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const WAIVED = 'waived';

    public const OPEN_STATUSES = [self::UNPAID, self::PARTIAL];

    protected $fillable = ['dues_period_id', 'household_id', 'amount_due', 'amount_paid', 'status', 'due_on'];

    protected function casts(): array
    {
        return ['amount_due' => 'integer', 'amount_paid' => 'integer', 'due_on' => 'date'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(DuesPeriod::class, 'dues_period_id');
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function outstanding(): int
    {
        return $this->status === self::WAIVED ? 0 : max($this->amount_due - $this->amount_paid, 0);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true) && $this->due_on->isPast() && ! $this->due_on->isToday();
    }

    /** Status dari jumlah terbayar; waived dipertahankan. */
    public function refreshStatus(): void
    {
        if ($this->status === self::WAIVED) {
            return;
        }
        $this->status = match (true) {
            $this->amount_paid >= $this->amount_due => self::PAID,
            $this->amount_paid > 0 => self::PARTIAL,
            default => self::UNPAID,
        };
    }
}
