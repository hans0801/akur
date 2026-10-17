<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/** Aman dijalankan di produksi (idempotent). */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            Role::SUPER_ADMIN => ['Super Admin', 'Konfigurasi lingkungan dan akun pengurus. Akses PII tetap diaudit.'],
            Role::KETUA_RW => ['Ketua RW', 'Ringkasan RW dan RT di bawahnya; rapat/laporan lintas RT.'],
            Role::KETUA_RT => ['Ketua RT', 'Data warga/keluarga, pengumuman, kegiatan, surat, ringkasan iuran pada RT-nya.'],
            Role::SEKRETARIS => ['Sekretaris', 'Administrasi surat, data administratif, agenda, notulen, laporan.'],
            Role::BENDAHARA => ['Bendahara', 'Iuran, pembayaran, transaksi kas, rekonsiliasi, laporan keuangan.'],
            Role::WARGA => ['Warga', 'Pengumuman/kegiatan, pengajuan surat, kewajiban dan riwayat pembayaran sendiri.'],
        ];

        foreach ($roles as $code => [$name, $description]) {
            Role::query()->updateOrCreate(['code' => $code], ['name' => $name, 'description' => $description]);
        }
    }
}
