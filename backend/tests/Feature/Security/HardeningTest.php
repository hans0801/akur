<?php

namespace Tests\Feature\Security;

use App\Logging\RedactSensitiveData;
use App\Logging\RedactSensitiveLogs;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    public function test_logs_never_contain_nik_tokens_or_signatures(): void
    {
        $path = storage_path('logs/hygiene-test.log');
        @unlink($path);
        config(['logging.channels.hygiene' => ['driver' => 'single', 'path' => $path, 'tap' => [RedactSensitiveLogs::class]]]);

        Log::channel('hygiene')->error('Gagal simpan NIK 3201010101015555 dengan Authorization: Bearer abcDEF123.xyz', [
            'nik' => '3201010101015555',
            'payload' => ['kk_number' => '3201019876543456', 'note' => 'KK 3201019876543456'],
            'url' => 'https://akur.test/api/v1/letters/x?expires=1&signature=abcdef0123',
            'id_token' => 'eyJhbGciOi.eyJzdWIiOi.c2lnbmF0dXJl',
            'exception' => new \RuntimeException('insert values (3201010101015555)'),
        ]);

        $log = file_get_contents($path);
        @unlink($path);
        foreach (['3201010101015555', '3201019876543456', 'abcDEF123', 'abcdef0123', 'eyJhbGciOi'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->assertStringContainsString('[REDACTED-16DIGIT]', $log);
        $this->assertStringContainsString('Gagal simpan NIK', $log);
    }

    public function test_redaction_keeps_ordinary_numbers(): void
    {
        $this->assertSame('Rp 250000 untuk 12 keluarga', RedactSensitiveData::scrub('Rp 250000 untuk 12 keluarga'));
        $this->assertSame('NIK [REDACTED-16DIGIT].', RedactSensitiveData::scrub('NIK 3201010101015555.'));
    }

    public function test_api_responses_are_not_cacheable_and_have_security_headers(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $response = $this->getJson('/api/v1/me', $this->bearerFor($this->userWithRole($rt, Role::WARGA)))->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_security_check_passes_locally_and_flags_production_misconfig(): void
    {
        $this->artisan('akur:security-check')->assertSuccessful();

        config(['app.debug' => true, 'app.url' => 'http://localhost']);
        $this->artisan('akur:security-check --production')
            ->expectsOutputToContain('[GAGAL] APP_DEBUG mati')
            ->assertFailed();
    }
}
