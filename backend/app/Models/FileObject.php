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

    public const CLASSIFICATIONS = [self::PAYMENT_PROOF, self::FINANCE_RECEIPT];

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
