<?php

namespace App\Services\Documents;

use App\Models\AdministrativeUnit;
use App\Models\DocumentRequest;

/**
 * Nomor surat berurutan per unit per tahun, mis. "007/RT05/RW06/X/2026".
 * Dipanggil di dalam transaksi; baris unit dikunci agar dua penerbitan bersamaan tidak memakai nomor sama
 * (indeks unik unit_id + letter_number menjadi pengaman terakhir).
 */
class LetterNumberer
{
    private const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    public function next(AdministrativeUnit $unit): string
    {
        AdministrativeUnit::query()->whereKey($unit->id)->lockForUpdate()->first();

        $now = now();
        $sequence = DocumentRequest::query()
            ->where('unit_id', $unit->id)
            ->whereNotNull('letter_number')
            ->whereYear('issued_at', $now->year)
            ->count() + 1;

        $parts = [str_pad((string) $sequence, 3, '0', STR_PAD_LEFT), strtoupper($unit->unit_type).$unit->code];
        if ($unit->parent) {
            $parts[] = strtoupper($unit->parent->unit_type).$unit->parent->code;
        }

        return implode('/', [...$parts, self::ROMAN[$now->month - 1], $now->year]);
    }
}
