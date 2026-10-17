<?php

namespace App\Services\Pii;

/**
 * Enkripsi field PII (NIK/KK) di lapisan aplikasi. Desain final enkripsi masih keputusan terbuka (W11),
 * jadi semua kode memakai antarmuka ini — implementasi bisa diganti (KMS, envelope encryption) tanpa mengubah pemanggil.
 *
 * `$field` mengikat ciphertext ke kolomnya (mis. "residents.nik") agar ciphertext tidak bisa dipindah ke kolom lain.
 */
interface PiiCipher
{
    /** Ciphertext biner, siap disimpan ke kolom bytea. */
    public function encrypt(string $plaintext, string $field): string;

    /** @throws PiiDecryptionFailed */
    public function decrypt(string $ciphertext, string $field): string;

    /** Keyed HMAC (kunci terpisah dari kunci enkripsi) untuk pencarian exact-match; biner. */
    public function lookupHash(string $normalized, string $field): string;
}
