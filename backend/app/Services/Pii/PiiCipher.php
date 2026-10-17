<?php

namespace App\Services\Pii;

/**
 * Enkripsi field PII (NIK/KK) di lapisan aplikasi. Semua kode memakai antarmuka ini sehingga implementasi bisa
 * diganti (mis. KMS/envelope encryption) tanpa mengubah pemanggil. Desain: docs/security/field-encryption.md.
 *
 * `$field` mengikat ciphertext ke kolomnya (mis. "residents.nik") agar ciphertext tidak bisa dipindah ke kolom lain.
 */
interface PiiCipher
{
    /** Ciphertext biner dengan kunci aktif, siap disimpan ke kolom bytea. */
    public function encrypt(string $plaintext, string $field): string;

    /** @throws PiiDecryptionFailed */
    public function decrypt(string $ciphertext, string $field): string;

    /** Keyed HMAC (kunci terpisah dari kunci enkripsi) untuk pencarian exact-match; biner. */
    public function lookupHash(string $normalized, string $field): string;

    /** True bila ciphertext sudah memakai format dan kunci aktif (tidak perlu dirotasi). */
    public function isCurrent(string $ciphertext): bool;
}
