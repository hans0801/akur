<?php

namespace Tests\Feature\Documents;

use App\Models\AdministrativeUnit;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\FileObject;
use App\Models\Resident;
use App\Models\Role;
use App\Models\UnitLetterSetting;
use App\Models\User;
use App\Services\Documents\LetterPdf;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LetterPdfTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
        Storage::fake('local');
    }

    public function test_letter_number_restarts_every_month_and_year(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketuaUser = $this->userWithRole($rt, Role::KETUA_RT);
        [$warga, $resident] = $this->linkedWarga($rt);

        $issue = function (string $at) use ($warga, $resident, $ketuaUser): string {
            Carbon::setTestNow(Carbon::parse($at, 'Asia/Jakarta'));
            // Sesi baru di "waktu" tersebut: sesi lama kedaluwarsa setelah 30 hari tidak dipakai.
            $id = $this->submit($warga, $resident);
            $this->postJson("/api/v1/document-requests/{$id}/transition", ['status' => 'approved'], $this->bearerFor($ketuaUser))->assertOk();

            return DocumentRequest::find($id)->letter_number;
        };

        $this->assertSame('001/RT005/SP/10/2026', $issue('2026-10-05 09:00'));
        $this->assertSame('002/RT005/SP/10/2026', $issue('2026-10-31 23:30'));   // masih Oktober waktu Jakarta
        $this->assertSame('001/RT005/SP/11/2026', $issue('2026-11-01 00:10'));   // ganti bulan → mulai dari 001
        $this->assertSame('001/RT005/SP/01/2027', $issue('2027-01-02 08:00'));   // ganti tahun → mulai dari 001
        $this->assertSame('002/RT005/SP/01/2027', $issue('2027-01-02 09:00'));
        Carbon::setTestNow();
    }

    public function test_settings_names_region_and_signatures_appear_in_letter(): void
    {
        ['rw' => $rw, 'rt1' => $rt] = $this->unitTree();
        $ketuaRtUser = $this->userWithRole($rt, Role::KETUA_RT);
        $ketuaRt = $this->bearerFor($ketuaRtUser);
        $ketuaRw = $this->bearerFor($this->userWithRole($rw, Role::KETUA_RW));

        $rtSignature = $this->upload($ketuaRt, 'letter_signature');
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['rt' => ['signer_name' => 'Ahmad Ketua RT', 'signature_file_id' => $rtSignature]], $ketuaRt)
            ->assertOk()->assertJsonPath('data.rt.signature_file_id', $rtSignature)->assertJsonPath('data.rt.can_edit', true)
            ->assertJsonPath('data.rw.can_edit', false);
        // Ketua RW mengisi kop dan namanya sendiri dari layar RT yang sama.
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", [
            'kelurahan' => 'Larangan Indah', 'kecamatan' => 'Larangan', 'city' => 'Kota Tangerang', 'province' => 'Banten',
            'rw' => ['signer_name' => 'Hendra Ketua RW'],
        ], $ketuaRw)->assertOk()->assertJsonPath('data.rw.signer_name', 'Hendra Ketua RW')->assertJsonPath('data.kop.city', 'Kota Tangerang');

        [$warga, $resident] = $this->linkedWarga($rt);
        $resident->update(['religion' => 'Islam', 'occupation' => 'Guru', 'marital_status' => 'Kawin', 'birth_place' => 'Tangerang', 'birth_date' => '1990-01-01']);
        $id = $this->submit($warga, $resident);
        $this->postJson("/api/v1/document-requests/{$id}/transition", ['status' => 'approved'], $ketuaRt)->assertOk();

        $html = app(LetterPdf::class)->htmlFor(DocumentRequest::find($id));
        foreach ([
            'RUKUN TETANGGA 005/006', 'KELURAHAN LARANGAN INDAH KECAMATAN LARANGAN', 'KOTA TANGERANG BANTEN',
            'Ketua RT. 005/006 Kelurahan Larangan Indah Kecamatan Larangan', 'Ketua RW 006', 'Pengurus RT.005/006',
            '(Ahmad Ketua RT)', '(Hendra Ketua RW)', 'Kota Tangerang, ', 'Islam', 'Guru', 'Kawin', 'Tangerang, 1 Januari 1990',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // Tanda tangan RT tertanam sebagai gambar; RW belum mengunggah, jadi hanya satu gambar tanda tangan + logo.
        $this->assertSame(2, substr_count($html, 'src="data:image/png;base64,'));
    }

    public function test_only_the_units_own_chairman_edits_settings(): void
    {
        ['rw' => $rw, 'rt1' => $rt] = $this->unitTree();
        $ketuaRw = $this->bearerFor($this->userWithRole($rw, Role::KETUA_RW));
        $sekretaris = $this->bearerFor($this->userWithRole($rt, Role::SEKRETARIS));

        // Ketua RW tidak boleh mengganti tanda tangan/nama Ketua RT, tetapi boleh melihat.
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['rt' => ['signer_name' => 'X']], $ketuaRw)->assertForbidden();
        $this->getJson("/api/v1/units/{$rt->id}/letter-settings", $ketuaRw)->assertOk()
            ->assertJsonPath('data.rt.can_edit', false)->assertJsonPath('data.rw.can_edit', true)->assertJsonPath('data.kop.can_edit', true);

        // Ketua RT tidak boleh mengganti nama/tanda tangan Ketua RW; permintaan campuran ditolak utuh.
        $ketuaRt = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['rw' => ['signer_name' => 'X']], $ketuaRt)->assertForbidden();
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['rt' => ['signer_name' => 'X'], 'rw' => ['signer_name' => 'Y']], $ketuaRt)->assertForbidden();
        $this->assertNull(UnitLetterSetting::query()->find($rt->id)?->signer_name);

        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['rt' => ['signer_name' => 'X']], $sekretaris)->assertForbidden();
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['city' => 'X'], $sekretaris)->assertForbidden();
        $this->getJson("/api/v1/units/{$rt->id}/letter-settings", $sekretaris)->assertOk();

        foreach ([Role::BENDAHARA, Role::WARGA] as $role) {
            $headers = $this->bearerFor($this->userWithRole($rt, $role));
            $this->getJson("/api/v1/units/{$rt->id}/letter-settings", $headers)->assertForbidden();
            $this->postJson('/api/v1/files', ['purpose' => 'letter_signature', 'file' => $this->png()], $headers)->assertForbidden();
        }
    }

    public function test_replacing_signature_removes_old_image_and_prune_keeps_current(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $first = $this->upload($ketua, 'letter_signature');
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['rt' => ['signature_file_id' => $first]], $ketua)->assertOk();

        $this->travel(2)->days();
        $this->artisan('akur:prune-files')->expectsOutputToContain('Dihapus: 0')->assertSuccessful();

        $second = $this->upload($ketua, 'letter_signature');
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['rt' => ['signature_file_id' => $second]], $ketua)->assertOk();
        $this->assertNull(FileObject::withTrashed()->find($first));
        $this->assertNotNull(FileObject::query()->find($second));

        // Gambar tanda tangan hanya PNG/JPEG.
        $pdf = UploadedFile::fake()->createWithContent('ttd.pdf', '%PDF-1.4 x');
        $this->post('/api/v1/files', ['purpose' => 'letter_signature', 'file' => $pdf], $ketua)->assertJsonValidationErrors('file');
    }

    public function test_sample_preview_is_a_pdf_via_signed_link(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));

        $url = $this->postJson("/api/v1/units/{$rt->id}/letter-settings/preview-link", [], $ketua)->assertOk()->json('data.url');
        $this->assertStringStartsWith('%PDF-', $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent());
        $this->get($url.'x')->assertForbidden();
    }

    public function test_settings_unit_list_includes_rts_below_rw_with_edit_flags(): void
    {
        ['rw' => $rw, 'rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();

        $admin = $this->bearerFor($this->userWithRole($rw, Role::SUPER_ADMIN));
        $units = collect($this->getJson('/api/v1/letter-settings/units', $admin)->assertOk()->json('data'));
        // Satu tampilan per RT (berisi Ketua RT dan Ketua RW); RW yang sudah punya RT tidak tampil terpisah.
        $this->assertSame([$rt1->id, $rt2->id], $units->pluck('id')->all());
        $this->assertTrue($units->every(fn ($u) => $u['can_edit']));
        $this->assertSame("{$rt1->name} / RW 06", $units->first()['label']);

        // Ketua RW membuka RT di bawahnya untuk mengisi bagian RW dan kop.
        $ketuaRw = $this->bearerFor($this->userWithRole($rw, Role::KETUA_RW));
        $flags = collect($this->getJson('/api/v1/letter-settings/units', $ketuaRw)->json('data'))->pluck('can_edit', 'id');
        $this->assertSame([$rt1->id => true, $rt2->id => true], $flags->all());

        $sekretaris = $this->bearerFor($this->userWithRole($rt1, Role::SEKRETARIS));
        $this->getJson('/api/v1/letter-settings/units', $sekretaris)->assertOk()->assertJsonPath('data.0.can_edit', false);

        // RW tanpa RT tetap bisa diatur sendiri.
        $lonelyRw = AdministrativeUnit::factory()->create(['code' => '09']);
        $lonelyKetua = $this->bearerFor($this->userWithRole($lonelyRw, Role::KETUA_RW));
        $this->getJson('/api/v1/letter-settings/units', $lonelyKetua)->assertJsonPath('data.0.id', $lonelyRw->id);
        $this->putJson("/api/v1/units/{$lonelyRw->id}/letter-settings", ['city' => 'Kota Tangerang', 'rw' => ['signer_name' => 'Hendra']], $lonelyKetua)
            ->assertOk()->assertJsonPath('data.rt', null)->assertJsonPath('data.rw.signer_name', 'Hendra')->assertJsonPath('data.kop.city', 'Kota Tangerang');

        $ketuaRt = $this->bearerFor($this->userWithRole($rt1, Role::KETUA_RT));
        $this->getJson('/api/v1/letter-settings/units', $ketuaRt)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_edit', true);

        // Super admin di RW boleh mengisi nama Ketua RT dan tanda tangannya.
        $this->putJson("/api/v1/units/{$rt1->id}/letter-settings", ['rt' => ['signer_name' => 'Ahmad Ketua RT'], 'rw' => ['signer_name' => 'Hendra']], $admin)->assertOk();
    }

    public function test_rt_screen_shows_rw_signature_and_region_fallback(): void
    {
        ['rw' => $rw, 'rt1' => $rt] = $this->unitTree();
        $ketuaRw = $this->bearerFor($this->userWithRole($rw, Role::KETUA_RW));
        $rwSignature = $this->upload($ketuaRw, 'letter_signature');
        $this->putJson("/api/v1/units/{$rw->id}/letter-settings", ['city' => 'Kota Tangerang', 'rw' => ['signature_file_id' => $rwSignature]], $ketuaRw)->assertOk();

        $ketuaRt = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $this->getJson("/api/v1/units/{$rt->id}/letter-settings", $ketuaRt)->assertOk()
            ->assertJsonPath('data.kop.city', 'Kota Tangerang')
            ->assertJsonPath('data.rw.signature_file_id', $rwSignature)
            ->assertJsonPath('data.rw.unit.id', $rw->id);
        // Ketua RT boleh melihat tanda tangan Ketua RW yang tampil di suratnya.
        $this->postJson("/api/v1/files/{$rwSignature}/link", [], $ketuaRt)->assertOk();
    }

    public function test_rw_sample_uses_an_rt_below_and_never_prints_000(): void
    {
        ['rw' => $rw] = $this->unitTree();

        $html = app(LetterPdf::class)->sampleHtml($rw);
        $this->assertStringContainsString('RUKUN TETANGGA 005/006', $html);
        $this->assertStringContainsString('/RT005/SP/', $html);
        $this->assertStringContainsString('Pengurus RT.005/006', $html);
        $this->assertStringNotContainsString('000/', $html);

        $lonelyRw = AdministrativeUnit::factory()->create(['code' => '09']);
        $html = app(LetterPdf::class)->sampleHtml($lonelyRw);
        $this->assertStringContainsString('RUKUN WARGA 009', $html);
        $this->assertStringNotContainsString('000/', $html);
        $this->assertStringNotContainsString('RT. 000', $html);
    }

    public function test_religion_is_validated_and_returned(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $resident = $this->residentIn($rt);

        $this->patchJson("/api/v1/residents/{$resident->id}", ['religion' => 'Lainnya'], $ketua)->assertJsonValidationErrors('religion');
        $this->patchJson("/api/v1/residents/{$resident->id}", ['religion' => 'Katolik'], $ketua)->assertOk()->assertJsonPath('data.religion', 'Katolik');
    }

    /** @return array{0: User, 1: Resident} */
    private function linkedWarga(AdministrativeUnit $unit): array
    {
        $user = $this->userWithRole($unit, Role::WARGA);

        return [$user, $this->residentIn($unit, ['user_id' => $user->id, 'full_name' => 'Siti Pemohon'])];
    }

    private function submit(User $user, Resident $resident): string
    {
        return $this->postJson('/api/v1/document-requests', [
            'document_type_id' => DocumentType::query()->where('code', 'pengantar_umum')->sole()->id,
            'resident_id' => $resident->id,
            'purpose' => 'Pembuatan KTP',
        ], $this->bearerFor($user))->assertCreated()->json('data.id');
    }

    private function upload(array $headers, string $purpose): string
    {
        return $this->post('/api/v1/files', ['purpose' => $purpose, 'file' => $this->png()], $headers)->assertCreated()->json('data.id');
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('ttd.png', self::PNG);
    }
}
