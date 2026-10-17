<?php

namespace Tests\Unit;

use App\Services\Pii\PiiDecryptionFailed;
use App\Services\Pii\SodiumPiiCipher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SodiumPiiCipherTest extends TestCase
{
    private function cipher(int $active = 1, ?array $keys = null): SodiumPiiCipher
    {
        return new SodiumPiiCipher($keys ?? [1 => str_repeat('e', 32)], $active, str_repeat('l', 32));
    }

    public function test_round_trip_with_random_nonce(): void
    {
        $cipher = $this->cipher();
        $a = $cipher->encrypt('3201010101010001', 'residents.nik');
        $b = $cipher->encrypt('3201010101010001', 'residents.nik');

        $this->assertNotSame($a, $b);
        $this->assertStringNotContainsString('3201010101010001', $a);
        $this->assertSame('3201010101010001', $cipher->decrypt($a, 'residents.nik'));
    }

    public function test_ciphertext_is_bound_to_its_field(): void
    {
        $cipher = $this->cipher();
        $ciphertext = $cipher->encrypt('3201010101010001', 'residents.nik');

        $this->expectException(PiiDecryptionFailed::class);
        $cipher->decrypt($ciphertext, 'households.kk_number');
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        $cipher = $this->cipher();
        $ciphertext = $cipher->encrypt('3201010101010001', 'residents.nik');
        $ciphertext[strlen($ciphertext) - 1] = chr(ord($ciphertext[strlen($ciphertext) - 1]) ^ 1);

        $this->expectException(PiiDecryptionFailed::class);
        $cipher->decrypt($ciphertext, 'residents.nik');
    }

    public function test_lookup_hash_is_deterministic_keyed_and_field_scoped(): void
    {
        $cipher = $this->cipher();
        $other = new SodiumPiiCipher([1 => str_repeat('e', 32)], 1, str_repeat('x', 32));

        $this->assertSame($cipher->lookupHash('3201', 'residents.nik'), $cipher->lookupHash('3201', 'residents.nik'));
        $this->assertNotSame($cipher->lookupHash('3201', 'residents.nik'), $cipher->lookupHash('3201', 'households.kk_number'));
        $this->assertNotSame($cipher->lookupHash('3201', 'residents.nik'), $other->lookupHash('3201', 'residents.nik'));
        $this->assertNotSame(hash('sha256', '3201', true), $cipher->lookupHash('3201', 'residents.nik'));
    }

    public function test_encryption_and_lookup_keys_must_differ(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SodiumPiiCipher([1 => str_repeat('k', 32)], 1, str_repeat('k', 32));
    }

    public function test_rotation_keeps_old_ciphertext_readable_and_marks_it_stale(): void
    {
        $keys = [1 => str_repeat('a', 32), 2 => str_repeat('b', 32)];
        $old = $this->cipher(1, [1 => $keys[1]])->encrypt('3201010101010001', 'residents.nik');
        $rotated = $this->cipher(2, $keys);

        $this->assertSame('3201010101010001', $rotated->decrypt($old, 'residents.nik'));
        $this->assertFalse($rotated->isCurrent($old));

        $fresh = $rotated->encrypt('3201010101010001', 'residents.nik');
        $this->assertTrue($rotated->isCurrent($fresh));
        $this->assertSame(2, ord($fresh[1]));
    }

    public function test_legacy_v1_ciphertext_is_read_with_key_one(): void
    {
        $key = str_repeat('a', 32);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $legacy = "\x01".$nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt('3201010101010001', 'residents.nik', $nonce, $key);

        $cipher = $this->cipher(2, [1 => $key, 2 => str_repeat('b', 32)]);
        $this->assertSame('3201010101010001', $cipher->decrypt($legacy, 'residents.nik'));
        $this->assertFalse($cipher->isCurrent($legacy));
    }

    public function test_missing_key_for_ciphertext_fails_closed(): void
    {
        $ciphertext = $this->cipher(2, [2 => str_repeat('b', 32)])->encrypt('x', 'residents.nik');

        $this->expectException(PiiDecryptionFailed::class);
        $this->cipher(1, [1 => str_repeat('a', 32)])->decrypt($ciphertext, 'residents.nik');
    }

    public function test_active_key_must_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cipher(3, [1 => str_repeat('a', 32)]);
    }
}
