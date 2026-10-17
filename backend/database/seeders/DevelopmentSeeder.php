<?php

namespace Database\Seeders;

use App\Models\AdministrativeUnit;
use App\Models\Household;
use App\Models\Resident;
use App\Models\UnitLetterSetting;
use Illuminate\Database\Seeder;

/**
 * Data SINTETIS untuk pengembangan/UAT awal. Tidak ada NIK/KK; jangan dijalankan di produksi.
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        $rw = AdministrativeUnit::query()->firstOrCreate(
            ['unit_type' => AdministrativeUnit::TYPE_RW, 'code' => '06', 'parent_unit_id' => null],
            ['name' => 'RW 06'],
        );

        $rt = AdministrativeUnit::query()->firstOrCreate(
            ['unit_type' => AdministrativeUnit::TYPE_RT, 'code' => '05', 'parent_unit_id' => $rw->id],
            ['name' => 'RT 05'],
        );

        // Kop surat contoh sesuai template; nama penanda tangan diambil dari pemegang peran bila kosong.
        UnitLetterSetting::query()->firstOrCreate(['unit_id' => $rw->id], [
            'kelurahan' => 'Larangan Indah',
            'kecamatan' => 'Larangan',
            'city' => 'Kota Tangerang',
            'province' => 'Banten',
        ]);

        if (Household::query()->where('unit_id', $rt->id)->exists()) {
            return;
        }

        $families = [
            ['Jl. Contoh No. 1', ['Budi Contoh', 'Sari Contoh', 'Rina Contoh']],
            ['Jl. Contoh No. 2', ['Agus Sintetis', 'Dewi Sintetis']],
            ['Jl. Contoh No. 3', ['Hendra Fiktif']],
        ];

        foreach ($families as [$address, $members]) {
            $household = Household::query()->create([
                'unit_id' => $rt->id,
                'address_text' => $address,
                'status' => 'active',
            ]);

            foreach ($members as $i => $name) {
                $resident = Resident::query()->create([
                    'household_id' => $household->id,
                    'full_name' => $name,
                    'is_household_head' => $i === 0,
                    'residency_status' => 'permanent',
                ]);

                if ($i === 0) {
                    $household->update(['head_resident_id' => $resident->id]);
                }
            }
        }
    }
}
