<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DuesPeriod extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $fillable = ['dues_type_id', 'period_code', 'starts_on', 'due_on', 'ends_on', 'status'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'due_on' => 'date', 'ends_on' => 'date'];
    }

    public function duesType(): BelongsTo
    {
        return $this->belongsTo(DuesType::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(DuesAssessment::class);
    }
}
