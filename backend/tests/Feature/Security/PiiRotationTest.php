<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Resident;
use App\Services\Pii\PiiCipher;
use App\Services\Pii\PopulationPii;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PiiRotationTest extends TestCase
{
    private const NIK = '3201010101015555';

    public function test_rotate_reencrypts_with_new_active_key_and_keeps_lookup_working(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $resident = $this->residentIn($rt);
        $resident->forceFill(app(PopulationPii::class)->columns(self::NIK, PopulationPii::NIK, 'nik'))->save();
        $this->assertSame(1, $this->keyIdOf($resident));

        $this->useKeys(['1:'.config('akur.pii.encryption_key'), '2:'.base64_encode(str_repeat('n', 32))], 2);

        $this->artisan('akur:pii-rotate --dry-run')->expectsOutputToContain('residents.nik: 1 baris perlu diproses')->assertSuccessful();
        $this->assertSame(1, $this->keyIdOf($resident));

        $this->artisan('akur:pii-rotate')->assertSuccessful();
        $this->assertSame(2, $this->keyIdOf($resident));
        $this->assertSame(self::NIK, $this->reveal($resident));
        $this->assertTrue(app(PopulationPii::class)->whereLookup(Resident::query(), 'nik_lookup_hmac', self::NIK, PopulationPii::NIK)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'pii.rotated')->exists());

        // Idempoten: menjalankan ulang tidak memproses apa pun.
        $this->artisan('akur:pii-rotate')->expectsOutputToContain('residents.nik: 0 baris diproses')->assertSuccessful();
    }

    public function test_rotate_all_rehashes_after_lookup_key_change(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $resident = $this->residentIn($rt);
        $resident->forceFill(app(PopulationPii::class)->columns(self::NIK, PopulationPii::NIK, 'nik'))->save();

        config(['akur.pii.lookup_key' => base64_encode(str_repeat('z', 32))]);
        app()->forgetInstance(PiiCipher::class);
        $lookup = fn () => app(PopulationPii::class)->whereLookup(Resident::query(), 'nik_lookup_hmac', self::NIK, PopulationPii::NIK)->exists();
        $this->assertFalse($lookup());

        $this->artisan('akur:pii-rotate --all')->assertSuccessful();
        $this->assertTrue($lookup());
    }

    /** @param  list<string>  $keys */
    private function useKeys(array $keys, int $active): void
    {
        config(['akur.pii.keys' => implode(',', $keys), 'akur.pii.active_key_id' => $active]);
        app()->forgetInstance(PiiCipher::class);
    }

    private function keyIdOf(Resident $resident): int
    {
        $hex = DB::table('residents')->where('id', $resident->id)->selectRaw("encode(nik_ciphertext, 'hex') AS h")->value('h');

        return str_starts_with($hex, '01') ? 1 : hexdec(substr($hex, 2, 2));
    }

    private function reveal(Resident $resident): ?string
    {
        return app(PopulationPii::class)->reveal(Resident::query()->find($resident->id)->getAttributes()['nik_ciphertext'], PopulationPii::NIK);
    }
}
