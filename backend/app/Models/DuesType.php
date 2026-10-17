<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DuesType extends Model
{
    use HasUuids;

    public const FREQUENCIES = ['monthly', 'yearly', 'once'];

    protected $fillable = ['unit_id', 'name', 'description', 'default_amount', 'frequency', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['default_amount' => 'integer', 'is_active' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AdministrativeUnit::class, 'unit_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(DuesPeriod::class);
    }
}
