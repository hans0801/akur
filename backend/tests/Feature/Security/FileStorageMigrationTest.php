<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Services\Files\PrivateFiles;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Pindah penyimpanan berkas privat dari volume VPS (`local`) ke Cloudflare R2 (`r2`).
 */
class FileStorageMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('r2');
    }

    public function test_new_files_go_to_the_configured_disk_encrypted(): void
    {
        config(['akur.files.disk' => 'r2']);
        $files = app(PrivateFiles::class);

        $file = $files->storeContent('%PDF surat', 'application/pdf', 'surat.pdf', 'document_output');

        Storage::disk('local')->assertMissing($file->storage_key);
        $stored = Storage::disk('r2')->get($file->storage_key);
        $this->assertStringNotContainsString('%PDF', $stored);
        $this->assertSame('%PDF surat', Crypt::decryptString($stored));
        $this->assertSame('%PDF surat', $files->read($file));
    }

    public function test_reads_fall_back_to_the_old_disk_until_migrated(): void
    {
        $files = app(PrivateFiles::class);
        $old = $files->storeContent('lama', 'image/png', 'a.png', 'payment_proof');

        config(['akur.files.disk' => 'r2', 'akur.files.fallback_disk' => 'local']);
        $this->assertSame('lama', $files->read($old));

        // Tanpa disk cadangan, berkas lama tidak terbaca.
        config(['akur.files.fallback_disk' => null]);
        $this->expectException(RuntimeException::class);
        $files->read($old);
    }

    public function test_migrate_command_copies_verifies_and_is_idempotent(): void
    {
        $files = app(PrivateFiles::class);
        $a = $files->storeContent('satu', 'image/png', 'a.png', 'payment_proof');
        $b = $files->storeContent('dua', 'application/pdf', 'b.pdf', 'document_output');
        config(['akur.files.disk' => 'r2']);

        $this->artisan('akur:files-migrate', ['--dry-run' => true])->expectsOutputToContain('Akan disalin: 2')->assertSuccessful();
        Storage::disk('r2')->assertMissing($a->storage_key);

        $this->artisan('akur:files-migrate')->expectsOutputToContain('Disalin: 2')->assertSuccessful();
        $this->assertSame('satu', $files->read($a));
        $this->assertSame('dua', $files->read($b));
        // Sumber tidak dihapus.
        Storage::disk('local')->assertExists($a->storage_key);
        $this->assertTrue(AuditLog::query()->where('action', 'files.migrated')->exists());

        $this->artisan('akur:files-migrate')->expectsOutputToContain('Disalin: 0, sudah ada: 2')->assertSuccessful();
    }

    public function test_tampered_source_fails_and_is_not_left_on_target(): void
    {
        $files = app(PrivateFiles::class);
        $file = $files->storeContent('asli', 'image/png', 'a.png', 'payment_proof');
        Storage::disk('local')->put($file->storage_key, Crypt::encryptString('diubah'));
        config(['akur.files.disk' => 'r2']);

        $this->artisan('akur:files-migrate')->expectsOutputToContain('gagal: 1')->assertFailed();
        Storage::disk('r2')->assertMissing($file->storage_key);
    }

    public function test_delete_removes_from_both_disks(): void
    {
        $files = app(PrivateFiles::class);
        $file = $files->storeContent('x', 'image/png', 'a.png', 'payment_proof');
        config(['akur.files.disk' => 'r2', 'akur.files.fallback_disk' => 'local']);
        $this->artisan('akur:files-migrate')->assertSuccessful();

        $files->delete($file);

        Storage::disk('local')->assertMissing($file->storage_key);
        Storage::disk('r2')->assertMissing($file->storage_key);
    }

    public function test_same_source_and_target_is_rejected(): void
    {
        $this->artisan('akur:files-migrate', ['--from' => 'local'])->assertFailed();
    }
}
