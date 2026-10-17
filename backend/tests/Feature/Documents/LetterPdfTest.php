<?php

namespace Tests\Feature\Documents;

use App\Models\AdministrativeUnit;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\FileObject;
use App\Models\Resident;
use App\Models\Role;
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
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['signer_name' => 'Ahmad Ketua RT', 'signature_file_id' => $rtSignature], $ketuaRt)
            ->assertOk()->assertJsonPath('data.signature_file_id', $rtSignature)->assertJsonPath('data.can_edit', true);
        $this->putJson("/api/v1/units/{$rw->id}/letter-settings", [
            'signer_name' => 'Hendra Ketua RW', 'kelurahan' => 'Larangan Indah', 'kecamatan' => 'Larangan', 'city' => 'Kota Tangerang', 'province' => 'Banten',
        ], $ketuaRw)->assertOk();

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
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['signer_name' => 'X'], $ketuaRw)->assertForbidden();
        $this->getJson("/api/v1/units/{$rt->id}/letter-settings", $ketuaRw)->assertOk()->assertJsonPath('data.can_edit', false);

        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['signer_name' => 'X'], $sekretaris)->assertForbidden();
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
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['signature_file_id' => $first], $ketua)->assertOk();

        $this->travel(2)->days();
        $this->artisan('akur:prune-files')->expectsOutputToContain('Dihapus: 0')->assertSuccessful();

        $second = $this->upload($ketua, 'letter_signature');
        $this->putJson("/api/v1/units/{$rt->id}/letter-settings", ['signature_file_id' => $second], $ketua)->assertOk();
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
