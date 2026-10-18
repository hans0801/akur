<?php

namespace App\Console\Commands;

use App\Models\FileObject;
use App\Services\Audit\AuditLogger;
use App\Services\Files\PrivateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Salin berkas privat dari disk lama (mis. volume VPS) ke disk aktif (akur.files.disk, mis. R2).
 * Isi disalin apa adanya (tetap terenkripsi APP_KEY), lalu dibaca ulang dari tujuan dan diverifikasi checksum.
 * Idempoten: berkas yang sudah ada di tujuan dilewati. Disk sumber tidak dihapus.
 */
class MigrateFiles extends Command
{
    protected $signature = 'akur:files-migrate {--from=local : Disk sumber} {--dry-run}';

    protected $description = 'Pindahkan berkas privat ke disk aktif (akur.files.disk) dengan verifikasi checksum';

    public function handle(PrivateFiles $files, AuditLogger $audit): int
    {
        $from = (string) $this->option('from');
        $to = (string) config('akur.files.disk');
        if ($from === $to) {
            $this->error("Disk sumber dan tujuan sama ({$to}).");

            return self::FAILURE;
        }

        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $copied = $skipped = $missing = $failed = 0;

        FileObject::query()->orderBy('id')->chunkById(100, function ($chunk) use ($files, $source, $target, &$copied, &$skipped, &$missing, &$failed) {
            foreach ($chunk as $file) {
                if ($target->exists($file->storage_key)) {
                    $skipped++;

                    continue;
                }
                if (! $source->exists($file->storage_key)) {
                    $missing++;
                    $this->warn("Tidak ada di sumber: {$file->id}");

                    continue;
                }
                if ($this->option('dry-run')) {
                    $copied++;

                    continue;
                }

                try {
                    $target->put($file->storage_key, $source->get($file->storage_key));
                    $files->decryptVerified($file, (string) $target->get($file->storage_key));
                    $copied++;
                } catch (Throwable $e) {
                    $failed++;
                    $target->delete($file->storage_key);
                    $this->error("Gagal {$file->id}: {$e->getMessage()}");
                }
            }
        });

        $prefix = $this->option('dry-run') ? 'Akan disalin' : 'Disalin';
        $this->line("{$prefix}: {$copied}, sudah ada: {$skipped}, tidak ada di sumber: {$missing}, gagal: {$failed} ({$from} → {$to})");
        if (! $this->option('dry-run') && $copied > 0) {
            $audit->log('files.migrated', 'file_object', metadata: compact('from', 'to', 'copied', 'skipped', 'missing', 'failed'));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
