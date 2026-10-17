<?php

namespace Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/**
 * Menyajikan JWKS palsu dari kunci RSA uji dan menandatangani ID token uji.
 * Verifikasi signature/iss/aud/exp di server tetap berjalan sungguhan.
 *
 * Kunci di tests/fixtures hanya untuk test (dibuat statis karena openssl_pkey_new/export
 * membutuhkan openssl.cnf yang sering tidak tersedia di Windows).
 */
trait FakesGoogleTokens
{
    protected function fakeGoogleJwks(): void
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->testKeyPem('google-test-key')));
        $b64 = static fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        Http::fake([
            config('akur.google.jwks_url') => Http::response([
                'keys' => [[
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => 'test-kid',
                    'n' => $b64($details['rsa']['n']),
                    'e' => $b64($details['rsa']['e']),
                ]],
            ], 200, ['Cache-Control' => 'public, max-age=3600']),
        ]);
    }

    protected function googleIdToken(array $overrides = [], string $signingKey = 'google-test-key'): string
    {
        $claims = $overrides + [
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-web-client.apps.googleusercontent.com',
            'sub' => '1098765432101234567890',
            'email' => 'warga@example.com',
            'email_verified' => true,
            'name' => 'Warga Contoh',
            'picture' => 'https://example.com/a.png',
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        return JWT::encode($claims, $this->testKeyPem($signingKey), 'RS256', 'test-kid');
    }

    private function testKeyPem(string $name): string
    {
        return file_get_contents(base_path("tests/fixtures/{$name}.pem"));
    }
}
