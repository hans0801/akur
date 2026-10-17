<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['payment_id', 'dues_assessment_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(DuesAssessment::class, 'dues_assessment_id');
    }
}
