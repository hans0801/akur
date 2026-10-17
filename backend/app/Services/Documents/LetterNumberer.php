<?php

namespace App\Services\Documents;

use App\Models\AdministrativeUnit;
use App\Models\DocumentRequest;
use Carbon\CarbonImmutable;

/**
 * Nomor surat pengantar: `{urut}/{RT005}/SP/{MM}/{yyyy}`, mis. "001/RT005/SP/10/2026".
 * Nomor urut per unit dan mengulang dari 001 setiap ganti bulan (dan otomatis setiap ganti tahun),
 * dihitung menurut waktu setempat (akur.report_timezone).
 * Dipanggil di dalam transaksi; baris unit dikunci agar dua penerbitan bersamaan tidak memakai nomor sama
 * (indeks unik unit_id + letter_number menjadi pengaman terakhir).
 */
class LetterNumberer
{
    public const KIND = 'SP';

    public function next(AdministrativeUnit $unit, ?CarbonImmutable $at = null): string
    {
        AdministrativeUnit::query()->whereKey($unit->id)->lockForUpdate()->first();

        $local = ($at ?? CarbonImmutable::now())->setTimezone(config('akur.report_timezone'));
        $monthStart = $local->startOfMonth();

        $sequence = DocumentRequest::query()
            ->where('unit_id', $unit->id)
            ->whereNotNull('letter_number')
            ->where('issued_at', '>=', $monthStart->utc())
            ->where('issued_at', '<', $monthStart->addMonth()->utc())
            ->count() + 1;

        return implode('/', [
            str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            self::unitCode($unit),
            self::KIND,
            $local->format('m'),
            $local->format('Y'),
        ]);
    }

    /** "RT005" / "RW006": jenis unit + kode 3 digit. */
    public static function unitCode(AdministrativeUnit $unit): string
    {
        return strtoupper($unit->unit_type).str_pad($unit->code, 3, '0', STR_PAD_LEFT);
    }
}
