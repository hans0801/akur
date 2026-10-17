<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Berkas privat (bukti bayar, nota kas). Isi terenkripsi di disk `local` (storage/app/private);
 * nama asli hanya metadata, tidak pernah dipakai sebagai path.
 */
class FileObject extends Model
{
    use HasUuids, SoftDeletes;

    public const UPDATED_AT = null;

    public const PAYMENT_PROOF = 'payment_proof';

    public const FINANCE_RECEIPT = 'finance_receipt';

    /** Gambar tanda tangan + cap penanda tangan surat (unit_letter_settings.signature_file_id). */
    public const LETTER_SIGNATURE = 'letter_signature';

    /** Logo kop surat (unit_letter_settings.logo_file_id). */
    public const LETTER_LOGO = 'letter_logo';

    /** PDF surat pengantar yang dibekukan saat terbit (document_requests.output_file_id); tidak diunggah pengguna. */
    public const LETTER_PDF = 'letter_pdf';

    /** Klasifikasi yang boleh diunggah lewat POST /files. */
    public const CLASSIFICATIONS = [self::PAYMENT_PROOF, self::FINANCE_RECEIPT, self::LETTER_SIGNATURE, self::LETTER_LOGO];

    /** Gambar untuk PDF: hanya PNG/JPEG (dompdf), maks 2 MB. */
    public const IMAGE_ONLY = [self::LETTER_SIGNATURE, self::LETTER_LOGO];

    protected $fillable = ['owner_user_id', 'storage_key', 'original_filename', 'mime_type', 'byte_size', 'classification', 'encryption_key_version'];

    protected $hidden = ['storage_key', 'checksum_sha256'];

    protected function casts(): array
    {
        return ['byte_size' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
