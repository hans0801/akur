<?php

namespace App\Console\Commands;

use App\Models\FileObject;
use App\Services\Audit\AuditLogger;
use App\Services\Files\PrivateFiles;
use Illuminate\Console\Command;

/**
 * Hapus berkas unggahan yang tidak pernah dilampirkan ke pembayaran/transaksi kas (mis. formulir dibatalkan),
 * agar data pribadi tidak menumpuk tanpa pemilik. Dijadwalkan harian (routes/console.php).
 */
class PruneOrphanFiles extends Command
{
    protected $signature = 'akur:prune-files {--dry-run}';

    protected $description = 'Hapus berkas privat yang tidak dilampirkan lebih lama dari akur.uploads.orphan_ttl_hours';

    public function handle(PrivateFiles $files, AuditLogger $audit): int
    {
        $deleted = 0;
        FileObject::query()
            ->where('created_at', '<', now()->subHours(config('akur.uploads.orphan_ttl_hours')))
            ->whereNotExists(fn ($q) => $q->from('payments')->whereColumn('payments.proof_file_id', 'file_objects.id'))
            ->whereNotExists(fn ($q) => $q->from('finance_transactions')->whereColumn('finance_transactions.receipt_file_id', 'file_objects.id'))
            ->whereNotExists(fn ($q) => $q->from('unit_letter_settings')->where(fn ($w) => $w
                ->whereColumn('unit_letter_settings.signature_file_id', 'file_objects.id')
                ->orWhereColumn('unit_letter_settings.logo_file_id', 'file_objects.id')))
            ->whereNotExists(fn ($q) => $q->from('document_requests')->whereColumn('document_requests.output_file_id', 'file_objects.id'))
            ->chunkById(100, function ($chunk) use ($files, &$deleted) {
                foreach ($chunk as $file) {
                    if (! $this->option('dry-run')) {
                        $files->delete($file);
                    }
                    $deleted++;
                }
            });

        $this->line(($this->option('dry-run') ? 'Akan dihapus: ' : 'Dihapus: ').$deleted.' berkas');
        if ($deleted > 0 && ! $this->option('dry-run')) {
            $audit->log('file.pruned', 'system', metadata: ['count' => $deleted]);
        }

        return self::SUCCESS;
    }
}
