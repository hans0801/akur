<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Role;
use Tests\TestCase;

/** W10: pesan untuk pengguna berbahasa Indonesia dan tidak membocorkan detail internal. */
class LocalizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('id');
    }

    public function test_validation_messages_are_indonesian_and_never_echo_values(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $household = Household::query()->create(['unit_id' => $rt->id, 'address_text' => 'A', 'status' => 'active']);

        $response = $this->postJson("/api/v1/households/{$household->id}/residents", ['nik' => '1234567890'], $headers)->assertUnprocessable();

        $this->assertSame('NIK harus 16 digit angka.', $response->json('errors.nik.0'));
        $this->assertSame('Nama lengkap wajib diisi.', $response->json('errors.full_name.0'));
        $this->assertStringContainsString('kesalahan lain', $response->json('message'));
        $this->assertStringNotContainsString('1234567890', $response->getContent());
    }

    public function test_missing_records_and_session_errors_are_generic(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));

        $response = $this->getJson('/api/v1/residents/'.str()->uuid(), $headers)->assertNotFound();
        $this->assertSame('Data tidak ditemukan.', $response->json('message'));
        $this->assertStringNotContainsString('App\\Models', $response->getContent());

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer salah', 'Accept' => 'application/json'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sesi Anda berakhir. Silakan masuk lagi.');
    }

    public function test_expired_print_link_shows_html_page(): void
    {
        ['rt1' => $rt] = $this->unitTree();

        $this->get("/api/v1/finance-exports/{$rt->id}?format=csv&month=2026-10&expires=1&signature=x")
            ->assertForbidden()
            ->assertSee('Tautan tidak berlaku');
    }
}
