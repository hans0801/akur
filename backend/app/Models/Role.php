<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public $timestamps = false;

    public const SUPER_ADMIN = 'super_admin';

    public const KETUA_RW = 'ketua_rw';

    public const KETUA_RT = 'ketua_rt';

    public const SEKRETARIS = 'sekretaris';

    public const BENDAHARA = 'bendahara';

    public const WARGA = 'warga';

    /** Peran pengurus (selain warga). */
    public const MANAGEMENT = [self::SUPER_ADMIN, self::KETUA_RW, self::KETUA_RT, self::SEKRETARIS, self::BENDAHARA];

    protected $fillable = ['code', 'name', 'description'];

    public static function idFor(string $code): int
    {
        return (int) static::query()->where('code', $code)->value('id');
    }
}
