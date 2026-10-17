<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceCategory extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    /** Kategori pemasukan iuran yang dibuat otomatis saat pembayaran pertama diverifikasi. */
    public const DUES_INCOME_NAME = 'Iuran warga';

    /** Kategori bawaan yang disiapkan untuk unit baru. */
    public const DEFAULTS = [
        self::INCOME => [self::DUES_INCOME_NAME, 'Donasi', 'Pemasukan lain'],
        self::EXPENSE => ['Kebersihan', 'Keamanan', 'Kegiatan warga', 'Sosial', 'Operasional'],
    ];

    protected $fillable = ['unit_id', 'name', 'kind', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AdministrativeUnit::class, 'unit_id');
    }
}
