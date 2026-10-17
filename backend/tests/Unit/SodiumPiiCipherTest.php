<?php

namespace Tests\Unit;

use App\Services\Pii\PiiDecryptionFailed;
use App\Services\Pii\SodiumPiiCipher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SodiumPiiCipherTest extends TestCase
{
    private function cipher(): SodiumPiiCipher
    {
        return new SodiumPiiCipher(str_repeat('e', 32), str_repeat('l', 32));
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
        $other = new SodiumPiiCipher(str_repeat('e', 32), str_repeat('x', 32));

        $this->assertSame($cipher->lookupHash('3201', 'residents.nik'), $cipher->lookupHash('3201', 'residents.nik'));
        $this->assertNotSame($cipher->lookupHash('3201', 'residents.nik'), $cipher->lookupHash('3201', 'households.kk_number'));
        $this->assertNotSame($cipher->lookupHash('3201', 'residents.nik'), $other->lookupHash('3201', 'residents.nik'));
        $this->assertNotSame(hash('sha256', '3201', true), $cipher->lookupHash('3201', 'residents.nik'));
    }

    public function test_encryption_and_lookup_keys_must_differ(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SodiumPiiCipher(str_repeat('k', 32), str_repeat('k', 32));
    }
}
