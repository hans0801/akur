<?php

use App\Models\Role;

$csv = static fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [

    'google' => [
        // Nilai `aud` yang diterima. Android Credential Manager memakai Web client ID sebagai serverClientId.
        'client_ids' => $csv(env('GOOGLE_CLIENT_IDS')),
        'issuers' => ['accounts.google.com', 'https://accounts.google.com'],
        'jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs',
        'leeway_seconds' => 60,
    ],

    'session' => [
        // Idle timeout bergeser setiap dipakai, dibatasi umur absolut.
        'idle_ttl_days' => (int) env('AKUR_SESSION_TTL_DAYS', 30),
        'absolute_ttl_days' => (int) env('AKUR_SESSION_ABSOLUTE_TTL_DAYS', 90),
        'cookie' => env('AKUR_SESSION_COOKIE', 'akur_session'),
        'cookie_secure' => (bool) env('AKUR_SESSION_COOKIE_SECURE', true),
    ],

    // Origin web yang boleh mengirim request berbasis cookie (CSRF + CORS).
    'web_origins' => $csv(env('AKUR_WEB_ORIGINS')),

    // Header wajib untuk request berbasis cookie yang mengubah data.
    'csrf_header' => 'X-AKUR-CSRF',

    // Matriks FR-03: peran pemberi → peran yang boleh ia tetapkan (di unit cakupannya).
    'grantable_roles' => [
        Role::SUPER_ADMIN => [Role::SUPER_ADMIN, Role::KETUA_RW, Role::KETUA_RT, Role::SEKRETARIS, Role::BENDAHARA, Role::WARGA],
        Role::KETUA_RW => [Role::KETUA_RT, Role::SEKRETARIS, Role::BENDAHARA, Role::WARGA],
        Role::KETUA_RT => [Role::SEKRETARIS, Role::BENDAHARA, Role::WARGA],
    ],

    // Peran yang boleh meninjau akun pending dan menautkannya ke data warga (FR-02).
    'linking_roles' => [Role::SUPER_ADMIN, Role::KETUA_RW, Role::KETUA_RT, Role::SEKRETARIS],

    // FR-04: data kependudukan. Ketua RW hanya membaca; bendahara dan warga tidak punya akses.
    'population_read_roles' => [Role::SUPER_ADMIN, Role::KETUA_RW, Role::KETUA_RT, Role::SEKRETARIS],
    'population_write_roles' => [Role::SUPER_ADMIN, Role::KETUA_RT, Role::SEKRETARIS],

    // W6: pengumuman dan kegiatan. Semua anggota unit (termasuk warga dan bendahara) membaca yang sudah terbit.
    'community_write_roles' => [Role::SUPER_ADMIN, Role::KETUA_RW, Role::KETUA_RT, Role::SEKRETARIS],

    // W8/W9: iuran dan kas. Bendahara mengelola; ketua RT/RW melihat ringkasan dan rincian.
    // Warga hanya melihat tagihan/pembayaran keluarganya sendiri dan ringkasan kas agregat.
    'finance_write_roles' => [Role::SUPER_ADMIN, Role::BENDAHARA],
    'finance_read_roles' => [Role::SUPER_ADMIN, Role::KETUA_RW, Role::KETUA_RT, Role::BENDAHARA],

    // Batas bulan pada laporan kas mengikuti waktu setempat, bukan UTC.
    'report_timezone' => env('AKUR_REPORT_TIMEZONE', 'Asia/Jakarta'),

    // W7: surat pengantar. Pemrosesan oleh pengurus RT; ketua RW hanya melihat antrean.
    'document_process_roles' => [Role::SUPER_ADMIN, Role::KETUA_RT, Role::SEKRETARIS],
    'document_read_roles' => [Role::SUPER_ADMIN, Role::KETUA_RW, Role::KETUA_RT, Role::SEKRETARIS],

    // Masa berlaku tautan cetak surat (berisi NIK) dalam menit.
    'letter_link_ttl_minutes' => 5,

    // Kunci enkripsi NIK/KK (32 byte acak, base64) dan kunci HMAC lookup yang BERBEDA. Lihat App\Services\Pii.
    'pii' => [
        'encryption_key' => env('AKUR_PII_ENCRYPTION_KEY'),
        'lookup_key' => env('AKUR_PII_LOOKUP_KEY'),
    ],

];
