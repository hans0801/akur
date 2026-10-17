<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Activity extends Model
{
    use HasUuids;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [self::STATUS_PLANNED, self::STATUS_CANCELLED, self::STATUS_COMPLETED];

    public const CATEGORIES = ['kerja_bakti', 'rapat', 'keagamaan', 'olahraga', 'sosial', 'perayaan', 'lainnya'];

    protected $fillable = ['unit_id', 'title', 'description', 'starts_at', 'ends_at', 'location_text', 'category', 'organizer_user_id', 'status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AdministrativeUnit::class, 'unit_id');
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ActivityParticipant::class);
    }
}
