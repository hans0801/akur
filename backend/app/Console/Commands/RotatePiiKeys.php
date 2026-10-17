<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\Resident;
use App\Services\Audit\AuditLogger;
use App\Services\Pii\PopulationPii;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * W11: enkripsi ulang NIK/KK dengan kunci PII aktif. Jalankan setelah menambah kunci baru (AKUR_PII_KEYS +
 * AKUR_PII_ACTIVE_KEY_ID), atau dengan --all setelah mengganti AKUR_PII_LOOKUP_KEY agar HMAC dihitung ulang.
 * Aman diulang: baris yang sudah memakai kunci aktif dilewati (kecuali --all). Nilai polos tidak pernah dicetak.
 */
class RotatePiiKeys extends Command
{
    protected $signature = 'akur:pii-rotate
        {--all : Proses semua baris (wajib setelah mengganti kunci lookup)}
        {--dry-run : Hanya hitung baris yang perlu diproses}
        {--chunk=200 : Jumlah baris per transaksi}';

    protected $description = 'Enkripsi ulang NIK/KK dengan kunci PII aktif dan hitung ulang HMAC lookup';

    /** @var list<array{0: class-string<Model>, 1: string, 2: string}> model, field, prefix kolom */
    private const TARGETS = [
        [Resident::class, PopulationPii::NIK, 'nik'],
        [Household::class, PopulationPii::KK, 'kk_number'],
    ];

    public function handle(PopulationPii $pii, AuditLogger $audit): int
    {
        $summary = [];
        foreach (self::TARGETS as [$model, $field, $prefix]) {
            $query = $model::query()
                ->when(method_exists($model, 'bootSoftDeletes'), fn ($q) => $q->withTrashed())
                ->whereNotNull("{$prefix}_ciphertext");

            $processed = 0;
            $query->chunkById((int) $this->option('chunk'), function ($rows) use ($pii, $field, $prefix, &$processed) {
                $due = $rows->filter(fn (Model $row) => $this->option('all') || $pii->needsRotation($row->getAttributes()["{$prefix}_ciphertext"]));
                if ($this->option('dry-run')) {
                    $processed += $due->count();

                    return;
                }
                DB::transaction(function () use ($due, $pii, $field, $prefix, &$processed) {
                    foreach ($due as $row) {
                        $columns = $pii->reencrypt($row->getAttributes()["{$prefix}_ciphertext"], $field, $prefix);
                        $row->newQuery()->withoutGlobalScopes()->whereKey($row->getKey())->update($columns);
                        $processed++;
                    }
                });
            });

            $summary[$field] = $processed;
            $this->line(sprintf('%s: %d baris %s', $field, $processed, $this->option('dry-run') ? 'perlu diproses' : 'diproses'));
        }

        if (! $this->option('dry-run')) {
            $audit->log('pii.rotated', 'system', metadata: ['rows' => $summary, 'all' => (bool) $this->option('all')]);
        }

        return self::SUCCESS;
    }
}
