<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Notifikasi in-app (tabel `notifications`). Dinamai AppNotification agar tidak
 * tertukar dengan sistem notifikasi bawaan Laravel.
 */
class AppNotification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'title', 'body', 'payload', 'read_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
