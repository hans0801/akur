<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class House extends Model
{
    use HasUuids;

    public const OCCUPANCY_STATUSES = ['occupied', 'vacant', 'rented', 'under_construction'];

    protected $fillable = ['street_id', 'house_number', 'block_label', 'occupancy_status'];

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }
}
