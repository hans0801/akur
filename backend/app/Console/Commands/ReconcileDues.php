<?php

namespace App\Console\Commands;

use App\Models\DuesAssessment;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PRD §9.1: amount_paid hanyalah cache dari alokasi pembayaran terverifikasi. Perintah ini membandingkan
 * keduanya dan (dengan --fix) memperbaiki selisih. Jalankan berkala (mis. harian) dan setelah pemulihan data.
 */
class ReconcileDues extends Command
{
    protected $signature = 'akur:reconcile-dues {--fix : Perbaiki amount_paid/status yang tidak cocok}';

    protected $description = 'Rekonsiliasi amount_paid tagihan iuran dengan alokasi pembayaran terverifikasi';

    public function handle(AuditLogger $audit): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT a.id, a.amount_paid, COALESCE(SUM(pa.amount) FILTER (WHERE p.status = 'verified'), 0) AS verified
            FROM dues_assessments a
            LEFT JOIN payment_allocations pa ON pa.dues_assessment_id = a.id
            LEFT JOIN payments p ON p.id = pa.payment_id
            GROUP BY a.id, a.amount_paid
            HAVING a.amount_paid <> COALESCE(SUM(pa.amount) FILTER (WHERE p.status = 'verified'), 0)
        SQL);

        if ($rows === []) {
            $this->info('Semua tagihan cocok.');

            return self::SUCCESS;
        }

        $this->table(['Tagihan', 'amount_paid', 'Terverifikasi'], array_map(fn ($r) => [$r->id, $r->amount_paid, $r->verified], $rows));

        if (! $this->option('fix')) {
            $this->warn(count($rows).' tagihan tidak cocok. Jalankan dengan --fix untuk memperbaiki.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($rows, $audit) {
            foreach ($rows as $row) {
                $assessment = DuesAssessment::query()->lockForUpdate()->findOrFail($row->id);
                $before = $assessment->amount_paid;
                $assessment->amount_paid = (int) $row->verified;
                $assessment->refreshStatus();
                $assessment->save();
                $audit->log('dues_assessment.reconciled', 'dues_assessment', $assessment->id, metadata: ['before' => $before, 'after' => $assessment->amount_paid]);
            }
        });
        $this->info(count($rows).' tagihan diperbaiki.');

        return self::SUCCESS;
    }
}
