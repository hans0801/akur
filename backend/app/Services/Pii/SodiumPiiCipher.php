<?php

namespace App\Services\Pii;

use InvalidArgumentException;
use SodiumException;

/**
 * XChaCha20-Poly1305 (libsodium, AEAD) dengan nonce acak 192-bit; nama field dipakai sebagai associated data.
 *
 * Format ciphertext:
 *   v1 (W5):  0x01 ‖ nonce(24) ‖ ciphertext+tag            — selalu kunci id 1
 *   v2 (W11): 0x02 ‖ key_id(1) ‖ nonce(24) ‖ ciphertext+tag — mendukung rotasi kunci
 * Data baru selalu v2 dengan kunci aktif; v1 tetap bisa dibaca sampai `akur:pii-rotate` dijalankan.
 * Lookup hash: HMAC-SHA256 dengan kunci terpisah (AKUR_PII_LOOKUP_KEY).
 */
class SodiumPiiCipher implements PiiCipher
{
    private const V1 = "\x01";

    private const V2 = "\x02";

    private const KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    /** @var array<int, string> */
    private readonly array $keys;

    /**
     * @param  array<int, string>  $keys  key_id (1–255) => 32 byte
     */
    public function __construct(
        #[\SensitiveParameter] array $keys,
        private readonly int $activeKeyId,
        #[\SensitiveParameter] private readonly string $lookupKey,
    ) {
        foreach ($keys as $id => $key) {
            if (! is_int($id) || $id < 1 || $id > 255 || strlen($key) !== self::KEY_BYTES) {
                throw new InvalidArgumentException('Setiap kunci PII harus 32 byte dengan id 1–255.');
            }
            if (hash_equals($key, $lookupKey)) {
                throw new InvalidArgumentException('Kunci enkripsi PII dan kunci lookup harus berbeda.');
            }
        }
        if (! isset($keys[$activeKeyId])) {
            throw new InvalidArgumentException("Kunci PII aktif (id {$activeKeyId}) tidak ada di keyring.");
        }
        if (strlen($lookupKey) < 32) {
            throw new InvalidArgumentException('Kunci lookup PII minimal 32 byte.');
        }
        $this->keys = $keys;
    }

    public static function fromConfig(): self
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', (string) config('akur.pii.keys')))) as $entry) {
            [$id, $value] = array_pad(explode(':', $entry, 2), 2, '');
            $keys[(int) $id] = self::decodeKey($value, "AKUR_PII_KEYS (id {$id})");
        }
        if (config('akur.pii.encryption_key') && ! isset($keys[1])) {
            $keys[1] = self::decodeKey(config('akur.pii.encryption_key'), 'AKUR_PII_ENCRYPTION_KEY');
        }
        if ($keys === []) {
            throw new InvalidArgumentException('Kunci PII belum diatur (AKUR_PII_ENCRYPTION_KEY atau AKUR_PII_KEYS).');
        }

        return new self(
            $keys,
            (int) (config('akur.pii.active_key_id') ?: max(array_keys($keys))),
            self::decodeKey(config('akur.pii.lookup_key'), 'AKUR_PII_LOOKUP_KEY'),
        );
    }

    public function encrypt(string $plaintext, string $field): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);

        return self::V2.chr($this->activeKeyId).$nonce
            .sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $field, $nonce, $this->keys[$this->activeKeyId]);
    }

    public function decrypt(string $ciphertext, string $field): string
    {
        [$keyId, $offset] = match ($ciphertext[0] ?? '') {
            self::V1 => [1, 1],
            self::V2 => [ord($ciphertext[1] ?? "\0"), 2],
            default => throw new PiiDecryptionFailed('Format ciphertext PII tidak dikenal.'),
        };
        if (strlen($ciphertext) <= $offset + self::NONCE_BYTES || ! isset($this->keys[$keyId])) {
            throw new PiiDecryptionFailed("Ciphertext PII rusak atau kunci id {$keyId} tidak tersedia.");
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($ciphertext, $offset + self::NONCE_BYTES),
                $field,
                substr($ciphertext, $offset, self::NONCE_BYTES),
                $this->keys[$keyId],
            );
        } catch (SodiumException) {
            $plaintext = false;
        }

        if ($plaintext === false) {
            throw new PiiDecryptionFailed('Ciphertext PII tidak valid untuk kunci/field ini.');
        }

        return $plaintext;
    }

    public function lookupHash(string $normalized, string $field): string
    {
        return hash_hmac('sha256', $field."\0".$normalized, $this->lookupKey, true);
    }

    public function isCurrent(string $ciphertext): bool
    {
        return ($ciphertext[0] ?? '') === self::V2 && ord($ciphertext[1] ?? "\0") === $this->activeKeyId;
    }

    private static function decodeKey(mixed $value, string $env): string
    {
        $raw = is_string($value) ? base64_decode(preg_replace('/^base64:/', '', $value), true) : false;
        if ($raw === false || $raw === '') {
            throw new InvalidArgumentException("{$env} belum diatur (32 byte acak, base64).");
        }

        return $raw;
    }
}
