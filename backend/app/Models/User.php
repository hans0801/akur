<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Akun aplikasi (bukan data kependudukan). Tidak ada password: login hanya via Google.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'display_name',
        'email',
        'email_verified_at',
        'avatar_url',
        'phone',
        'account_status',
        'last_login_at',
    ];

    protected $attributes = [
        'account_status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function identities(): HasMany
    {
        return $this->hasMany(AuthIdentity::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserMembership::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function resident(): HasOne
    {
        return $this->hasOne(Resident::class);
    }

    public function canSignIn(): bool
    {
        return in_array($this->account_status, [self::STATUS_PENDING, self::STATUS_ACTIVE], true);
    }

    // Tidak ada remember-token/password; nonaktifkan agar Laravel tidak mencoba menulisnya.
    public function getRememberTokenName()
    {
        return '';
    }
}
