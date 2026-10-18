<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan aplikasi global (kunci → nilai JSON). Hanya super_admin yang membaca/mengubahnya lewat API.
 */
class AppSetting extends Model
{
    public const MAINTENANCE = 'maintenance';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** @return array{enabled: bool, message: ?string, updated_at: ?string} */
    public static function maintenance(): array
    {
        $setting = static::query()->find(self::MAINTENANCE);

        return [
            'enabled' => (bool) ($setting?->value['enabled'] ?? false),
            'message' => $setting?->value['message'] ?? null,
            'updated_at' => $setting?->updated_at?->toIso8601String(),
        ];
    }
}
