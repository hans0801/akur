<?php

namespace App\Services\Pii;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * NIK/KK: normalisasi, enkripsi ke kolom bytea, lookup HMAC, dan masking.
 * Nilai polos tidak pernah disimpan, dicatat ke log, maupun dikirim utuh kecuali lewat endpoint "reveal" yang diaudit.
 */
class PopulationPii
{
    public const NIK = 'residents.nik';

    public const KK = 'households.kk_number';

    public function __construct(private readonly PiiCipher $cipher) {}

    /** Hanya digit; NIK dan No. KK sama-sama 16 digit. */
    public static function normalize(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }

    /**
     * Nilai kolom `{prefix}_ciphertext` dan `{prefix}_lookup_hmac` untuk disimpan (null menghapus keduanya).
     *
     * @return array<string, Expression|null>
     */
    public function columns(?string $value, string $field, string $prefix): array
    {
        if ($value === null || $value === '') {
            return ["{$prefix}_ciphertext" => null, "{$prefix}_lookup_hmac" => null];
        }

        $normalized = self::normalize($value);

        return [
            "{$prefix}_ciphertext" => self::bytea($this->cipher->encrypt($normalized, $field)),
            "{$prefix}_lookup_hmac" => self::bytea($this->cipher->lookupHash($normalized, $field)),
        ];
    }

    /** Batasi query ke baris dengan nilai PII tertentu (exact match via HMAC). */
    public function whereLookup(Builder $query, string $column, string $value, string $field): Builder
    {
        return $query->whereRaw("{$column} = decode(?, 'hex')", [bin2hex($this->cipher->lookupHash(self::normalize($value), $field))]);
    }

    public function reveal(mixed $ciphertext, string $field): ?string
    {
        $binary = self::readBinary($ciphertext);

        return $binary === null ? null : $this->cipher->decrypt($binary, $field);
    }

    /** Hanya 4 digit terakhir yang terlihat. */
    public function masked(mixed $ciphertext, string $field): ?string
    {
        $plain = $this->reveal($ciphertext, $field);

        return $plain === null ? null : str_repeat('*', max(strlen($plain) - 4, 0)).substr($plain, -4);
    }

    /** Literal hex (bukan input pengguna) agar biner aman disisipkan tanpa binding teks PDO. */
    private static function bytea(string $binary): Expression
    {
        return DB::raw("decode('".bin2hex($binary)."', 'hex')");
    }

    /** pdo_pgsql mengembalikan bytea sebagai stream. */
    private static function readBinary(mixed $value): ?string
    {
        if (is_resource($value)) {
            rewind($value);
            $value = stream_get_contents($value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
