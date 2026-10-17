<?php

namespace App\Services\Pii;

use InvalidArgumentException;
use SodiumException;

/**
 * XChaCha20-Poly1305 (libsodium, AEAD) dengan nonce acak 192-bit; nama field dipakai sebagai associated data.
 * Format ciphertext: 1 byte versi ‖ 24 byte nonce ‖ ciphertext+tag.
 * Lookup hash: HMAC-SHA256 dengan kunci terpisah (AKUR_PII_LOOKUP_KEY).
 */
class SodiumPiiCipher implements PiiCipher
{
    private const VERSION = "\x01";

    public function __construct(
        #[\SensitiveParameter] private readonly string $encryptionKey,
        #[\SensitiveParameter] private readonly string $lookupKey,
    ) {
        if (strlen($encryptionKey) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES || strlen($lookupKey) < 32) {
            throw new InvalidArgumentException('Kunci PII harus 32 byte (base64). Lihat AKUR_PII_ENCRYPTION_KEY / AKUR_PII_LOOKUP_KEY.');
        }
        if (hash_equals($encryptionKey, $lookupKey)) {
            throw new InvalidArgumentException('Kunci enkripsi PII dan kunci lookup harus berbeda.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            self::decodeKey(config('akur.pii.encryption_key'), 'AKUR_PII_ENCRYPTION_KEY'),
            self::decodeKey(config('akur.pii.lookup_key'), 'AKUR_PII_LOOKUP_KEY'),
        );
    }

    public function encrypt(string $plaintext, string $field): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return self::VERSION.$nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $field, $nonce, $this->encryptionKey);
    }

    public function decrypt(string $ciphertext, string $field): string
    {
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($ciphertext) <= 1 + $nonceLength || $ciphertext[0] !== self::VERSION) {
            throw new PiiDecryptionFailed('Format ciphertext PII tidak dikenal.');
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($ciphertext, 1 + $nonceLength),
                $field,
                substr($ciphertext, 1, $nonceLength),
                $this->encryptionKey,
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

    private static function decodeKey(mixed $value, string $env): string
    {
        $raw = is_string($value) ? base64_decode(preg_replace('/^base64:/', '', $value), true) : false;
        if ($raw === false || $raw === '') {
            throw new InvalidArgumentException("{$env} belum diatur (32 byte acak, base64).");
        }

        return $raw;
    }
}
