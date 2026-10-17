<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Resident extends Model
{
    use HasUuids, SoftDeletes;

    // Status yang dapat dipilih saat input/koreksi. moved_out dan deceased diset lewat mutasi.
    public const RESIDENCY_STATUSES = ['permanent', 'contract', 'temporary'];

    public const STATUS_MOVED_OUT = 'moved_out';

    public const STATUS_DECEASED = 'deceased';

    public const GENDERS = ['male', 'female'];

    public const RELIGIONS = ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Konghucu', 'Kepercayaan'];

    protected $fillable = [
        'household_id', 'user_id', 'full_name', 'birth_place', 'birth_date', 'gender', 'marital_status',
        'occupation', 'education', 'religion', 'is_household_head', 'is_alive', 'residency_status', 'moved_in_at', 'moved_out_at',
    ];

    // NIK hanya boleh keluar lewat endpoint khusus dengan masking + audit (FR-04).
    protected $hidden = ['nik_ciphertext', 'nik_lookup_hmac'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'moved_in_at' => 'date',
            'moved_out_at' => 'date',
            'is_household_head' => 'boolean',
            'is_alive' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ResidentMovement::class);
    }

    public function isActiveMember(): bool
    {
        return $this->is_alive && $this->residency_status !== self::STATUS_MOVED_OUT;
    }

    /** Lepas status kepala keluarga (sebelum pindah/meninggal). Simpan model setelahnya. */
    public function releaseHeadship(): void
    {
        if ($this->household_id !== null) {
            Household::query()->whereKey($this->household_id)->where('head_resident_id', $this->id)->update(['head_resident_id' => null]);
        }
        $this->is_household_head = false;
    }
}
