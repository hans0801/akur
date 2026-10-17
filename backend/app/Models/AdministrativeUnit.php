<?php

namespace App\Models;

use Database\Factories\AdministrativeUnitFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdministrativeUnit extends Model
{
    /** @use HasFactory<AdministrativeUnitFactory> */
    use HasFactory, HasUuids;

    public const TYPE_RW = 'rw';

    public const TYPE_RT = 'rt';

    protected $fillable = ['parent_unit_id', 'unit_type', 'code', 'name', 'address', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_unit_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_unit_id');
    }

    /**
     * ID unit ini beserta semua leluhurnya (RT → RW). Hierarki hanya dua tingkat.
     *
     * @return list<string>
     */
    public function selfAndAncestorIds(): array
    {
        return array_values(array_filter([$this->id, $this->parent_unit_id]));
    }
}
