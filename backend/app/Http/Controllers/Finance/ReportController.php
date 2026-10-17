<?php

namespace App\Http\Controllers\Finance;

use App\Models\AdministrativeUnit;
use App\Models\DuesAssessment;
use App\Models\DuesPeriod;
use App\Services\Finance\Ledger;
use App\Services\Reports\OperationalReports;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * W9: laporan kas. Laporan agregat (saldo, total per kategori, rekap iuran) terbuka bagi semua anggota unit
 * demi transparansi; tidak memuat nama keluarga, alamat, atau rincian transaksi.
 */
class ReportController extends FinanceController
{
    /** Laporan bulanan: saldo awal, pemasukan/pengeluaran per kategori, saldo akhir, rekap iuran bulan itu. */
    public function monthly(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->ensureMember($request, $unit);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $tz = config('akur.report_timezone');
        $start = CarbonImmutable::createFromFormat('Y-m', $data['month'] ?? now($tz)->format('Y-m'), $tz)->startOfMonth();

        return response()->json(['data' => $this->buildMonthly($unit, $start)]);
    }

    /**
     * Isi laporan bulanan (dipakai JSON dan versi cetak).
     *
     * @return array<string, mixed>
     */
    public function buildMonthly(AdministrativeUnit $unit, CarbonImmutable $start): array
    {
        $ledger = app(Ledger::class);
        $end = $start->addMonth();

        $rows = DB::table('finance_transactions as ft')
            ->join('finance_categories as c', 'c.id', '=', 'ft.category_id')
            ->where('ft.unit_id', $unit->id)
            ->where('ft.occurred_at', '>=', $start->utc())
            ->where('ft.occurred_at', '<', $end->utc())
            ->groupBy('c.id', 'c.name', 'c.kind')
            ->select('c.id', 'c.name', 'c.kind')
            ->selectRaw('SUM('.Ledger::signedAmountSql().') AS net')
            ->get();

        $income = $rows->where('kind', 'income')->map(fn ($r) => ['category' => $r->name, 'amount' => (int) $r->net])->values();
        $expense = $rows->where('kind', 'expense')->map(fn ($r) => ['category' => $r->name, 'amount' => -(int) $r->net])->values();
        $opening = $ledger->balance([$unit->id], $start->utc());

        return [
            'unit' => ['id' => $unit->id, 'name' => $unit->label()],
            'month' => $start->format('Y-m'),
            'opening_balance' => $opening,
            'income' => $income->all(),
            'expense' => $expense->all(),
            'total_income' => $income->sum('amount'),
            'total_expense' => $expense->sum('amount'),
            'closing_balance' => $opening + $income->sum('amount') - $expense->sum('amount'),
            'dues' => $this->duesSummary($unit, $start, $end),
        ];
    }

    /** FR-11: laporan operasional (tunggakan, pembayaran, surat, kegiatan) untuk pengurus yang berhak. */
    public function operational(Request $request, AdministrativeUnit $unit, string $type, OperationalReports $reports): JsonResponse
    {
        abort_unless(in_array($type, OperationalReports::TYPES, true), 404);
        $this->access->authorize($request->user(), $unit, OperationalReports::roles($type));
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $tz = config('akur.report_timezone');
        $start = CarbonImmutable::createFromFormat('Y-m', $data['month'] ?? now($tz)->format('Y-m'), $tz)->startOfMonth();

        return response()->json(['data' => $reports->build($type, $unit, $start)]);
    }

    /** Ringkasan per bulan dalam satu tahun untuk grafik/transparansi. */
    public function yearly(Request $request, AdministrativeUnit $unit, Ledger $ledger): JsonResponse
    {
        $this->ensureMember($request, $unit);
        $data = $request->validate(['year' => ['nullable', 'integer', 'min:2020', 'max:2100']]);

        $tz = config('akur.report_timezone');
        $year = (int) ($data['year'] ?? now($tz)->year);
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $tz);

        $months = [];
        $balance = $ledger->balance([$unit->id], $start->utc());
        for ($m = 0; $m < 12; $m++) {
            $from = $start->addMonths($m);
            $to = $from->addMonth();
            $flows = DB::table('finance_transactions as ft')
                ->join('finance_categories as c', 'c.id', '=', 'ft.category_id')
                ->where('ft.unit_id', $unit->id)
                ->where('ft.occurred_at', '>=', $from->utc())
                ->where('ft.occurred_at', '<', $to->utc())
                ->selectRaw("COALESCE(SUM(CASE WHEN c.kind = 'income' THEN ".Ledger::signedAmountSql().' END), 0) AS income')
                ->selectRaw("COALESCE(-SUM(CASE WHEN c.kind = 'expense' THEN ".Ledger::signedAmountSql().' END), 0) AS expense')
                ->first();
            $balance += (int) $flows->income - (int) $flows->expense;
            $months[] = ['month' => $from->format('Y-m'), 'income' => (int) $flows->income, 'expense' => (int) $flows->expense, 'closing_balance' => $balance];
        }

        return response()->json(['data' => ['unit' => ['id' => $unit->id, 'name' => $unit->label()], 'year' => $year, 'months' => $months]]);
    }

    /** @return list<array<string, mixed>> */
    private function duesSummary(AdministrativeUnit $unit, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DuesPeriod::query()
            ->whereHas('duesType', fn ($q) => $q->where('unit_id', $unit->id))
            ->where('due_on', '>=', $start->toDateString())
            ->where('due_on', '<', $end->toDateString())
            ->with('duesType:id,name')
            ->get()
            ->map(function (DuesPeriod $p) {
                $assessments = $p->assessments();

                return [
                    'dues_type' => $p->duesType->name,
                    'period_code' => $p->period_code,
                    'households' => $assessments->count(),
                    'paid_households' => (clone $assessments)->whereIn('status', [DuesAssessment::PAID, DuesAssessment::WAIVED])->count(),
                    'total_due' => (int) (clone $assessments)->where('status', '!=', DuesAssessment::WAIVED)->sum('amount_due'),
                    'total_paid' => (int) (clone $assessments)->sum('amount_paid'),
                ];
            })
            ->all();
    }

    /** Anggota unit mana pun (termasuk warga) boleh melihat laporan agregat unitnya dan RW induknya. */
    private function ensureMember(Request $request, AdministrativeUnit $unit): void
    {
        if (! in_array($unit->id, $this->access->visibleUnitIds($request->user()), true)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke unit ini.');
        }
    }
}
