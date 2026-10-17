<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat mutasi warga (FR-04). Perubahan alamat/status selalu ditambahkan di sini, tidak pernah menimpa riwayat.
 */
class ResidentMovement extends Model
{
    use HasUuids;

    public const MOVE_IN = 'move_in';

    public const MOVE_OUT = 'move_out';

    public const MOVE_HOUSE = 'move_house';

    public const DEATH = 'death';

    public const CORRECTION = 'correction';

    public const TYPES = [self::MOVE_IN, self::MOVE_OUT, self::MOVE_HOUSE, self::DEATH, self::CORRECTION];

    public const UPDATED_AT = null;

    protected $fillable = ['resident_id', 'movement_type', 'from_household_id', 'to_household_id', 'effective_date', 'reason', 'recorded_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date'];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
