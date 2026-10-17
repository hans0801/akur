<?php

namespace App\Services\Reports;

use App\Models\AdministrativeUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FR-11: laporan operasional per unit dan periode, dalam satu bentuk tabel
 * {title, columns[{key,label,type}], rows[], totals{key: {label,type,value}}} agar layar aplikasi dan ekspor CSV memakai kode yang sama.
 * Tidak memuat NIK/KK. Hak baca per jenis laporan ada di ROLES (diperiksa controller).
 */
class OperationalReports
{
    public const TYPES = ['arrears', 'payments', 'documents', 'activities'];

    /** @return list<string> */
    public static function roles(string $type): array
    {
        return match ($type) {
            'arrears', 'payments' => config('akur.finance_read_roles'),
            'documents' => config('akur.document_read_roles'),
            'activities' => config('akur.community_write_roles'),
        };
    }

    /** @return array{title: string, subtitle: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, totals: array<string, mixed>} */
    public function build(string $type, AdministrativeUnit $unit, CarbonImmutable $start): array
    {
        $unitIds = $unit->selfAndDescendantIds();
        $end = $start->addMonth();

        return match ($type) {
            'arrears' => $this->arrears($unitIds),
            'payments' => $this->payments($unitIds, $start, $end),
            'documents' => $this->documents($unitIds, $start, $end),
            'activities' => $this->activities($unitIds, $start, $end),
        } + ['unit' => ['id' => $unit->id, 'name' => $unit->label()], 'month' => $start->format('Y-m')];
    }

    /** @return array{label: string, type: string, value: int} */
    private static function total(string $label, int $value, string $type = 'number'): array
    {
        return ['label' => $label, 'type' => $type, 'value' => $value];
    }

    /** Tunggakan per keluarga per hari ini (tagihan terbuka yang sudah lewat jatuh tempo). */
    private function arrears(array $unitIds): array
    {
        $rows = DB::table('dues_assessments as a')
            ->join('households as h', 'h.id', '=', 'a.household_id')
            ->join('administrative_units as u', 'u.id', '=', 'h.unit_id')
            ->leftJoin('residents as r', 'r.id', '=', 'h.head_resident_id')
            ->whereIn('h.unit_id', $unitIds)
            ->whereIn('a.status', ['unpaid', 'partial'])
            ->where('a.due_on', '<', now(config('akur.report_timezone'))->toDateString())
            ->groupBy('h.id', 'h.address_text', 'r.full_name', 'u.name')
            ->orderByRaw('SUM(a.amount_due - a.amount_paid) DESC')
            ->select('u.name as unit', 'r.full_name as head_name', 'h.address_text as address')
            ->selectRaw('COUNT(*) AS open_bills, SUM(a.amount_due - a.amount_paid) AS outstanding, MIN(a.due_on) AS oldest_due')
            ->get()
            ->map(fn ($r) => array_merge((array) $r, ['outstanding' => (int) $r->outstanding, 'open_bills' => (int) $r->open_bills]))
            ->all();

        return [
            'title' => 'Tunggakan iuran',
            'subtitle' => 'Tagihan belum lunas yang sudah lewat jatuh tempo, per hari ini.',
            'columns' => [
                ['key' => 'head_name', 'label' => 'Kepala keluarga', 'type' => 'text'],
                ['key' => 'address', 'label' => 'Alamat', 'type' => 'text'],
                ['key' => 'unit', 'label' => 'Unit', 'type' => 'text'],
                ['key' => 'open_bills', 'label' => 'Tagihan', 'type' => 'number'],
                ['key' => 'oldest_due', 'label' => 'Jatuh tempo terlama', 'type' => 'date'],
                ['key' => 'outstanding', 'label' => 'Tunggakan', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => [
                'households' => self::total('Keluarga menunggak', count($rows)),
                'outstanding' => self::total('Total tunggakan', array_sum(array_column($rows, 'outstanding')), 'money'),
            ],
        ];
    }

    private function payments(array $unitIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('payments as p')
            ->join('households as h', 'h.id', '=', 'p.household_id')
            ->leftJoin('residents as r', 'r.id', '=', 'h.head_resident_id')
            ->leftJoin('users as v', 'v.id', '=', 'p.verified_by')
            ->whereIn('h.unit_id', $unitIds)
            ->where('p.paid_at', '>=', $start->utc())
            ->where('p.paid_at', '<', $end->utc())
            ->orderBy('p.paid_at')
            ->select('p.paid_at', 'r.full_name as head_name', 'h.address_text as address', 'p.payment_method as method', 'p.amount', 'p.status', 'v.display_name as verified_by')
            ->get()
            ->map(fn ($r) => array_merge((array) $r, ['amount' => (int) $r->amount]))
            ->all();

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + $row['amount'];
        }
        // Kode → label Indonesia untuk tampilan dan CSV (setelah total dihitung dari kode).
        $methods = ['cash' => 'Tunai', 'transfer' => 'Transfer bank', 'qris' => 'QRIS', 'ewallet' => 'E-wallet'];
        $statuses = ['submitted' => 'Menunggu verifikasi', 'verified' => 'Terverifikasi', 'rejected' => 'Ditolak', 'refunded' => 'Dikembalikan'];
        $rows = array_map(fn ($r) => array_merge($r, [
            'method' => $methods[$r['method']] ?? $r['method'],
            'status' => $statuses[$r['status']] ?? $r['status'],
        ]), $rows);

        return [
            'title' => 'Pembayaran iuran',
            'subtitle' => 'Semua pembayaran yang dibayar pada bulan ini, termasuk yang ditolak/dikembalikan.',
            'columns' => [
                ['key' => 'paid_at', 'label' => 'Waktu bayar', 'type' => 'datetime'],
                ['key' => 'head_name', 'label' => 'Kepala keluarga', 'type' => 'text'],
                ['key' => 'address', 'label' => 'Alamat', 'type' => 'text'],
                ['key' => 'method', 'label' => 'Metode', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                ['key' => 'verified_by', 'label' => 'Diverifikasi oleh', 'type' => 'text'],
                ['key' => 'amount', 'label' => 'Nominal', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => [
                'count' => self::total('Jumlah pembayaran', count($rows)),
                'verified' => self::total('Terverifikasi', $byStatus['verified'] ?? 0, 'money'),
                'submitted' => self::total('Menunggu verifikasi', $byStatus['submitted'] ?? 0, 'money'),
                'rejected' => self::total('Ditolak/dikembalikan', ($byStatus['rejected'] ?? 0) + ($byStatus['refunded'] ?? 0), 'money'),
            ],
        ];
    }

    private function documents(array $unitIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $statuses = ['submitted', 'under_review', 'revision_requested', 'approved', 'rejected', 'issued', 'cancelled'];
        $counts = DB::table('document_requests as d')
            ->join('document_types as t', 't.id', '=', 'd.document_type_id')
            ->whereIn('d.unit_id', $unitIds)
            ->where('d.submitted_at', '>=', $start->utc())
            ->where('d.submitted_at', '<', $end->utc())
            ->groupBy('t.name', 'd.status')
            ->select('t.name', 'd.status')
            ->selectRaw('COUNT(*) AS n')
            ->get();

        $rows = [];
        foreach ($counts as $c) {
            $rows[$c->name] ??= ['document_type' => $c->name, ...array_fill_keys($statuses, 0), 'total' => 0];
            $rows[$c->name][$c->status] = (int) $c->n;
            $rows[$c->name]['total'] += (int) $c->n;
        }
        ksort($rows);

        $labels = ['submitted' => 'Diajukan', 'under_review' => 'Ditinjau', 'revision_requested' => 'Perbaikan', 'approved' => 'Disetujui',
            'rejected' => 'Ditolak', 'issued' => 'Terbit', 'cancelled' => 'Batal'];

        return [
            'title' => 'Status surat pengantar',
            'subtitle' => 'Pengajuan yang masuk pada bulan ini menurut status terkini.',
            'columns' => [
                ['key' => 'document_type', 'label' => 'Jenis surat', 'type' => 'text'],
                ...array_map(fn ($s) => ['key' => $s, 'label' => $labels[$s], 'type' => 'number'], $statuses),
                ['key' => 'total', 'label' => 'Total', 'type' => 'number'],
            ],
            'rows' => array_values($rows),
            'totals' => [
                'total' => self::total('Pengajuan', array_sum(array_column($rows, 'total'))),
                'issued' => self::total('Terbit', array_sum(array_column($rows, 'issued'))),
            ],
        ];
    }

    private function activities(array $unitIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('activities as a')
            ->join('administrative_units as u', 'u.id', '=', 'a.unit_id')
            ->leftJoin('activity_participants as p', 'p.activity_id', '=', 'a.id')
            ->whereIn('a.unit_id', $unitIds)
            ->where('a.starts_at', '>=', $start->utc())
            ->where('a.starts_at', '<', $end->utc())
            ->groupBy('a.id', 'a.title', 'a.starts_at', 'a.status', 'u.name')
            ->orderBy('a.starts_at')
            ->select('a.starts_at', 'a.title', 'u.name as unit', 'a.status')
            ->selectRaw("COUNT(*) FILTER (WHERE p.participation_status = 'going') AS going")
            ->selectRaw("COUNT(*) FILTER (WHERE p.participation_status = 'maybe') AS maybe")
            ->selectRaw("COUNT(*) FILTER (WHERE p.participation_status = 'not_going') AS not_going")
            ->selectRaw('COUNT(p.checked_in_at) AS checked_in')
            ->get()
            ->map(fn ($r) => array_merge((array) $r, [
                'status' => ['planned' => 'Terjadwal', 'cancelled' => 'Dibatalkan', 'completed' => 'Selesai'][$r->status] ?? $r->status,
                'going' => (int) $r->going,
                'maybe' => (int) $r->maybe,
                'not_going' => (int) $r->not_going,
                'checked_in' => (int) $r->checked_in,
            ]))
            ->all();

        return [
            'title' => 'Partisipasi kegiatan',
            'subtitle' => 'Kegiatan yang dimulai pada bulan ini: konfirmasi kehadiran dan check-in.',
            'columns' => [
                ['key' => 'starts_at', 'label' => 'Mulai', 'type' => 'datetime'],
                ['key' => 'title', 'label' => 'Kegiatan', 'type' => 'text'],
                ['key' => 'unit', 'label' => 'Unit', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                ['key' => 'going', 'label' => 'Hadir', 'type' => 'number'],
                ['key' => 'maybe', 'label' => 'Mungkin', 'type' => 'number'],
                ['key' => 'not_going', 'label' => 'Tidak', 'type' => 'number'],
                ['key' => 'checked_in', 'label' => 'Check-in', 'type' => 'number'],
            ],
            'rows' => $rows,
            'totals' => [
                'activities' => self::total('Kegiatan', count($rows)),
                'checked_in' => self::total('Total check-in', array_sum(array_column($rows, 'checked_in'))),
            ],
        ];
    }
}
