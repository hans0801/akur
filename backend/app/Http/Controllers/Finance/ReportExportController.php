<?php

namespace App\Http\Controllers\Finance;

use App\Models\AdministrativeUnit;
use App\Models\FinanceTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * W9: ekspor laporan kas. Klien meminta tautan bertanda tangan berumur pendek (aplikasi/browser eksternal
 * tidak membawa token Bearer), lalu membuka CSV rincian transaksi atau laporan bulanan siap cetak.
 */
class ReportExportController extends FinanceController
{
    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function link(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeRead($request, $unit);
        $data = $request->validate([
            'format' => ['required', 'in:csv,html'],
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $expires = now()->addMinutes(config('akur.letter_link_ttl_minutes'));
        $url = URL::temporarySignedRoute('finance.export', $expires, ['unit' => $unit->id, 'format' => $data['format'], 'month' => $data['month']]);
        $this->audit->log('finance_report.export_link_created', 'administrative_unit', $unit->id, $unit->id, $data);

        return response()->json(['data' => ['url' => $url, 'expires_at' => $expires->toIso8601String()]])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, AdministrativeUnit $unit, ReportController $reports): Response|StreamedResponse
    {
        $month = (string) $request->query('month');
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);
        $tz = config('akur.report_timezone');
        $start = CarbonImmutable::createFromFormat('Y-m', $month, $tz)->startOfMonth();

        return $request->query('format') === 'csv'
            ? $this->csv($unit, $start)
            : $this->html($unit, $start, $reports);
    }

    private function csv(AdministrativeUnit $unit, CarbonImmutable $start): StreamedResponse
    {
        $tz = config('akur.report_timezone');
        $transactions = FinanceTransaction::query()
            ->where('unit_id', $unit->id)
            ->where('occurred_at', '>=', $start->utc())
            ->where('occurred_at', '<', $start->addMonth()->utc())
            ->with(['category:id,name', 'creator:id,display_name', 'reversed:id,description'])
            ->orderBy('occurred_at')
            ->get();

        $filename = 'kas-'.str($unit->name)->slug().'-'.$start->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($transactions, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8.
            fputcsv($out, ['Tanggal', 'Jenis', 'Kategori', 'Keterangan', 'Masuk', 'Keluar', 'Dicatat oleh', 'ID transaksi', 'Membalik ID'], ';');
            foreach ($transactions as $t) {
                $signed = match ($t->kind) {
                    'income' => $t->amount,
                    'expense' => -$t->amount,
                    default => $t->reversed?->kind === 'income' ? -$t->amount : $t->amount,
                };
                fputcsv($out, array_map([self::class, 'safeCell'], [
                    $t->occurred_at->setTimezone($tz)->format('Y-m-d H:i'),
                    ['income' => 'Pemasukan', 'expense' => 'Pengeluaran', 'reversal' => 'Pembalikan'][$t->kind],
                    $t->category?->name,
                    $t->description,
                    $signed > 0 ? $signed : '',
                    $signed < 0 ? -$signed : '',
                    $t->creator?->display_name,
                    $t->id,
                    $t->reversed_transaction_id,
                ]), ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function html(AdministrativeUnit $unit, CarbonImmutable $start, ReportController $reports): Response
    {
        $report = $reports->buildMonthly($unit, $start);

        return response(view('reports.monthly', [
            'report' => $report,
            'monthLabel' => self::MONTHS[$start->month - 1].' '.$start->year,
            'printedAt' => now(config('akur.report_timezone'))->format('d/m/Y H:i'),
        ])->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'",
        ]);
    }

    /** Cegah CSV/formula injection saat dibuka di spreadsheet. */
    public static function safeCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
