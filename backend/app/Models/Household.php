<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    use HasUuids;

    public const STATUSES = ['active', 'moved_out', 'inactive'];

    protected $fillable = ['unit_id', 'household_number', 'head_resident_id', 'street_id', 'house_id', 'address_text', 'status'];

    // Kolom terenkripsi tidak pernah ikut serialisasi JSON.
    protected $hidden = ['kk_number_ciphertext', 'kk_number_lookup_hmac'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AdministrativeUnit::class, 'unit_id');
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'head_resident_id');
    }

    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }
}
