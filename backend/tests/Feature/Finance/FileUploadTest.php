<?php

namespace Tests\Feature\Finance;

use App\Models\AdministrativeUnit;
use App\Models\DuesType;
use App\Models\FileObject;
use App\Models\FinanceCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_warga_uploads_proof_attaches_it_and_treasurer_can_view(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        [$warga] = $this->linkedWarga($rt);
        $this->openPeriod($rt, $bendahara);
        $headers = $this->bearerFor($warga);

        $fileId = $this->post('/api/v1/files', ['purpose' => 'payment_proof', 'file' => $this->png('bukti.png')], $headers)
            ->assertCreated()->assertJsonPath('data.mime_type', 'image/png')->json('data.id');

        // Isi di disk terenkripsi, bukan gambar asli.
        $stored = Storage::disk('local')->get(FileObject::query()->find($fileId)->storage_key);
        $this->assertStringNotContainsString('IHDR', $stored);

        $paymentId = $this->postJson('/api/v1/payments', ['amount' => 10000, 'payment_method' => 'transfer', 'proof_file_id' => $fileId], $headers)
            ->assertCreated()->assertJsonPath('data.proof_file_id', $fileId)->json('data.id');

        // Berkas yang sama tidak bisa dipakai pembayaran lain (masih ada sisa tagihan 10.000).
        $this->postJson('/api/v1/payments', ['amount' => 5000, 'payment_method' => 'transfer', 'proof_file_id' => $fileId], $headers)
            ->assertJsonValidationErrors('proof_file_id');

        $url = $this->postJson("/api/v1/files/{$fileId}/link", [], $this->bearerFor($bendahara))->assertOk()->json('data.url');
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(self::PNG, $response->getContent());
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));

        $this->assertNotNull($paymentId);
    }

    public function test_rejects_disguised_and_oversized_files(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->linkedWarga($rt)[0]);

        // Skrip yang diberi nama .png ditolak karena tipe diperiksa dari isi (finfo), bukan ekstensi.
        // Berkas sungguhan dipakai karena UploadedFile::fake() menebak tipe dari nama berkas.
        $path = tempnam(sys_get_temp_dir(), 'akur');
        file_put_contents($path, '<?php echo "x";');
        $disguised = new UploadedFile($path, 'bukti.png', null, null, true);
        $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => $disguised], $headers)->assertJsonValidationErrors('file');
        @unlink($path);

        $big = UploadedFile::fake()->create('besar.pdf', config('akur.uploads.max_kb') + 1, 'application/pdf');
        $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => $big], $headers)->assertJsonValidationErrors('file');

        $this->assertSame(0, FileObject::query()->count());
    }

    public function test_other_households_cannot_view_proof_and_warga_cannot_upload_receipts(): void
    {
        ['rt1' => $rt, 'rt2' => $rt2] = $this->unitTree();
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        [$warga] = $this->linkedWarga($rt);
        [$neighbour] = $this->linkedWarga($rt);
        $this->openPeriod($rt, $bendahara);
        $fileId = $this->post('/api/v1/files', ['purpose' => 'payment_proof', 'file' => $this->png()], $this->bearerFor($warga))->json('data.id');
        $this->postJson('/api/v1/payments', ['amount' => 20000, 'payment_method' => 'transfer', 'proof_file_id' => $fileId], $this->bearerFor($warga))->assertCreated();

        $this->postJson("/api/v1/files/{$fileId}/link", [], $this->bearerFor($neighbour))->assertForbidden();
        $this->postJson("/api/v1/files/{$fileId}/link", [], $this->bearerFor($this->userWithRole($rt2, Role::BENDAHARA)))->assertForbidden();
        $this->postJson("/api/v1/files/{$fileId}/link", [], $this->bearerFor($warga))->assertOk();

        $this->post('/api/v1/files', ['purpose' => 'finance_receipt', 'file' => $this->png()], $this->bearerFor($warga) + ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_cash_receipt_is_attached_at_creation_and_restricted_to_finance_roles(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        $headers = $this->bearerFor($bendahara);
        $category = FinanceCategory::query()->create(['unit_id' => $rt->id, 'kind' => 'expense', 'name' => 'Kebersihan', 'is_active' => true]);
        $fileId = $this->post('/api/v1/files', ['purpose' => 'finance_receipt', 'file' => $this->png('nota.png')], $headers)->json('data.id');

        $this->postJson("/api/v1/units/{$rt->id}/finance-transactions", [
            'category_id' => $category->id, 'amount' => 50000, 'occurred_at' => now()->toIso8601String(),
            'description' => 'Beli kantong sampah', 'receipt_file_id' => $fileId,
        ], $headers)->assertCreated()->assertJsonPath('data.receipt_file_id', $fileId);

        $this->postJson("/api/v1/files/{$fileId}/link", [], $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT)))->assertOk();
        $this->postJson("/api/v1/files/{$fileId}/link", [], $this->bearerFor($this->userWithRole($rt, Role::WARGA)))->assertForbidden();
    }

    public function test_orphan_uploads_are_pruned_after_ttl(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->linkedWarga($rt)[0]);
        $fileId = $this->post('/api/v1/files', ['purpose' => 'payment_proof', 'file' => $this->png()], $headers)->json('data.id');
        $key = FileObject::query()->find($fileId)->storage_key;

        $this->artisan('akur:prune-files')->expectsOutputToContain('Dihapus: 0')->assertSuccessful();
        $this->travel(25)->hours();
        $this->artisan('akur:prune-files')->expectsOutputToContain('Dihapus: 1')->assertSuccessful();

        $this->assertNull(FileObject::withTrashed()->find($fileId));
        Storage::disk('local')->assertMissing($key);
    }

    private function png(string $name = 'bukti.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PNG);
    }

    /** @return array{0: User} */
    private function linkedWarga(AdministrativeUnit $unit): array
    {
        $user = $this->userWithRole($unit, Role::WARGA);
        $this->residentIn($unit, ['user_id' => $user->id]);

        return [$user];
    }

    private function openPeriod(AdministrativeUnit $unit, User $bendahara): void
    {
        $type = DuesType::query()->create(['unit_id' => $unit->id, 'name' => 'Iuran', 'default_amount' => 20000, 'frequency' => 'monthly', 'created_by' => $bendahara->id]);
        $this->postJson("/api/v1/dues-types/{$type->id}/periods", ['period_code' => '2026-10', 'starts_on' => '2026-10-01', 'due_on' => '2026-10-10'], $this->bearerFor($bendahara))
            ->assertCreated();
    }
}
