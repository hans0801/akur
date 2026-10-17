<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengaturan surat per unit (RT/RW): nama penanda tangan, wilayah untuk kop, logo kop, dan gambar tanda tangan + cap.
 * Gambar disimpan sebagai berkas privat terenkripsi (FileObject).
 */
class UnitLetterSetting extends Model
{
    protected $primaryKey = 'unit_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['unit_id', 'signer_name', 'kelurahan', 'kecamatan', 'city', 'province', 'logo_file_id', 'signature_file_id', 'updated_by'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AdministrativeUnit::class, 'unit_id');
    }

    public function logo(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'logo_file_id');
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'signature_file_id');
    }
}
