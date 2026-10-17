<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Resident extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'household_id', 'user_id', 'full_name', 'birth_place', 'birth_date', 'gender', 'marital_status',
        'occupation', 'education', 'is_household_head', 'is_alive', 'residency_status', 'moved_in_at', 'moved_out_at',
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
}
