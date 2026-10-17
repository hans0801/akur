<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/** Jenis surat pengantar bawaan. Aman dijalankan di produksi (idempotent). */
class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $text = fn (string $key, string $label, bool $required = true, string $type = 'text') => compact('key', 'label', 'type', 'required');

        $types = [
            'pengantar_umum' => ['Surat Pengantar Umum', 'Pengantar ke kelurahan untuk keperluan administrasi umum.', []],
            'domisili' => ['Surat Keterangan Domisili', 'Keterangan bahwa warga berdomisili di wilayah RT.', [
                $text('lama_tinggal', 'Lama tinggal (mis. 3 tahun)'),
            ]],
            'skck' => ['Pengantar SKCK', 'Pengantar pembuatan SKCK di kepolisian.', []],
            'usaha' => ['Surat Keterangan Usaha', 'Keterangan memiliki usaha di wilayah RT.', [
                $text('nama_usaha', 'Nama usaha'),
                $text('jenis_usaha', 'Jenis usaha'),
                $text('alamat_usaha', 'Alamat usaha', true, 'textarea'),
            ]],
            'tidak_mampu' => ['Surat Keterangan Tidak Mampu', 'Pengantar SKTM ke kelurahan.', [
                $text('penghasilan_bulanan', 'Perkiraan penghasilan per bulan (Rp)', true, 'number'),
                $text('jumlah_tanggungan', 'Jumlah tanggungan', true, 'number'),
            ]],
            'kelahiran' => ['Pengantar Akta Kelahiran', 'Pengantar pengurusan akta kelahiran.', [
                $text('nama_bayi', 'Nama bayi'),
                $text('tanggal_lahir_bayi', 'Tanggal lahir bayi', true, 'date'),
                $text('tempat_lahir_bayi', 'Tempat lahir bayi'),
            ]],
            'kematian' => ['Pengantar Akta Kematian', 'Pengantar pengurusan akta kematian untuk warga yang meninggal.', [
                $text('tanggal_meninggal', 'Tanggal meninggal', true, 'date'),
                $text('tempat_meninggal', 'Tempat meninggal'),
                $text('sebab_meninggal', 'Sebab meninggal', false),
            ]],
        ];

        foreach ($types as $code => [$name, $description, $fields]) {
            DocumentType::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'required_fields' => $fields, 'is_active' => true],
            );
        }
    }
}
