<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequest extends Model
{
    use HasUuids;

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const REVISION_REQUESTED = 'revision_requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const ISSUED = 'issued';

    public const CANCELLED = 'cancelled';

    /** Transisi yang boleh dilakukan pengurus: status asal → status tujuan. */
    public const REVIEW_TRANSITIONS = [
        self::SUBMITTED => [self::UNDER_REVIEW, self::REVISION_REQUESTED, self::APPROVED, self::REJECTED],
        self::UNDER_REVIEW => [self::REVISION_REQUESTED, self::APPROVED, self::REJECTED],
        self::APPROVED => [self::ISSUED],
    ];

    /** Status yang masih bisa dibatalkan pemohon. */
    public const CANCELLABLE = [self::SUBMITTED, self::REVISION_REQUESTED];

    protected $fillable = [
        'unit_id', 'requester_user_id', 'resident_id', 'document_type_id', 'purpose', 'form_data', 'status',
        'admin_notes', 'processed_by', 'processed_at', 'submitted_at', 'letter_number', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'processed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AdministrativeUnit::class, 'unit_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
