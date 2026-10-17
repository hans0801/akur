<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only; trigger database menolak UPDATE/DELETE. */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'unit_id', 'action', 'entity_type', 'entity_id', 'occurred_at', 'request_id', 'ip_prefix', 'metadata'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
