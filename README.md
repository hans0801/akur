# AKUR — Aplikasi Kerukunan Warga

Administrasi RT/RW untuk Android, iOS, dan Web. Lihat PRD v0.1 untuk ruang lingkup.

| Folder | Isi |
|---|---|
| `backend/` | Laravel 13 API (PHP 8.3) + PostgreSQL 17 |
| `app/` | Flutter (Android + iOS + Web), tema terracotta |
| `infra/` | Caddy (edge/TLS), init Postgres dev |
| `compose.yaml` | Stack produksi/pilot VPS (Caddy + API + Postgres) |
| `compose.dev.yaml` | Postgres lokal untuk pengembangan |

## Status implementasi

Selesai (fondasi W3 + Auth W4):

- Skema PostgreSQL seluruh tabel MVP (PRD §9, tanpa marketplace fase 2): UUID, `timestamptz`, uang `bigint`,
  FK/UNIQUE/CHECK, audit log *append-only* (trigger menolak UPDATE/DELETE).
- Login Google: ID token diverifikasi di server (signature JWKS, `iss`, `aud`, `exp`), identitas dikunci pada `sub`.
- Akun baru selalu `pending` tanpa peran; pengurus menautkannya ke data warga (FR-02).
- Sesi milik AKUR: token opak, hanya SHA-256 yang disimpan, bisa dicabut, sliding expiry 30 hari (maks. 90).
  Android → Bearer + secure storage. Web → cookie `HttpOnly; Secure; SameSite=Strict` + header CSRF.
- RBAC per unit (RW mencakup RT di bawahnya), matriks pemberian peran, larangan mengubah peran sendiri,
  audit log setiap perubahan peran/penautan, rate limit login & endpoint sensitif.
- Notifikasi in-app (gagal kirim tidak menggagalkan transaksi utama).
- 42 test backend (auth, IDOR lintas RT, eskalasi peran, CSRF, audit) + test Flutter.

Selesai W5 (FR-04): CRUD jalan, rumah, keluarga (KK), warga, dan mutasi per RT/RW; NIK/KK terenkripsi
(`App\Services\Pii\PiiCipher`, kunci `AKUR_PII_ENCRYPTION_KEY` + `AKUR_PII_LOOKUP_KEY`), selalu dimasking,
tampil utuh hanya lewat endpoint *reveal* yang diaudit. Perubahan alamat/status selalu dicatat sebagai mutasi.

Selesai W6: pengumuman (draf/terbit/arsip, jadwal tayang & kedaluwarsa) dan kegiatan (RSVP, check-in) per RT/RW.
Warga RT melihat konten RT-nya dan RW induknya; pengurus (super_admin, ketua_rw, ketua_rt, sekretaris) menulis.
Notifikasi in-app saat terbit/dibuat/dibatalkan. Belum: notifikasi untuk pengumuman terjadwal, foto kegiatan.

Selesai W7: surat pengantar. Warga mengajukan untuk dirinya/anggota keluarganya (7 jenis surat bawaan,
isian per jenis), pengurus RT meninjau → minta perbaikan / setujui / tolak → terbitkan dengan nomor
`001/RT05/RW06/X/2026`. Surat siap cetak (HTML A4, memuat NIK) dibuka lewat tautan bertanda tangan 5 menit; diaudit.
Pemohon tidak bisa memproses suratnya sendiri. Belum: lampiran KTP/KK, PDF di server, tanda tangan digital.

Selesai W8: iuran. Bendahara membuat jenis iuran dan periode (tagihan otomatis untuk semua keluarga aktif),
warga melapor pembayaran (dialokasikan ke tagihan terlama, `Idempotency-Key` mencegah ganda), bendahara
memverifikasi/menolak atau mencatat tunai; pembayaran terverifikasi otomatis masuk kas sebagai pemasukan.
Ketua RT/RW melihat rincian. Rekonsiliasi cache `amount_paid`: `php artisan akur:reconcile-dues [--fix]`.
Bukti bayar dan nota kas diunggah (JPEG/PNG/WebP/PDF, maks 5 MB, tipe dicek dari isi), disimpan terenkripsi
dengan APP_KEY di `storage/app/private`, dibuka lewat tautan bertanda tangan; unggahan yatim dihapus harian oleh
service `scheduler`. Tanpa payment gateway (di luar MVP).

Selesai W9: buku kas per unit (kategori bawaan, pemasukan/pengeluaran manual, pembalikan; baris kas
*append-only* dijaga trigger DB), refund pembayaran iuran (membalik pemasukan dan membuka lagi tagihan),
laporan bulanan & tahunan agregat yang terbuka untuk semua warga unit (rincian transaksi hanya pengurus keuangan).
Batas bulan laporan memakai `AKUR_REPORT_TIMEZONE` (bawaan Asia/Jakarta). Ekspor laporan (CSV transaksi dan versi
cetak/PDF) lewat tautan bertanda tangan 5 menit, khusus pengurus keuangan.
Laporan pengurus FR-11 per unit/bulan: tunggakan, pembayaran (keuangan), status surat (sekretaris/ketua),
partisipasi kegiatan (pengurus), masing-masing dengan ekspor CSV/cetak hanya untuk peran yang berhak.


Selesai W10 (penyelesaian UI): seluruh UI dan pesan API berbahasa Indonesia (pemilih tanggal, pesan validasi
`backend/lang/id`, 401/404/429/tautan kedaluwarsa tanpa detail internal), tampilan galat seragam dengan "Coba lagi",
batas waktu request 20 detik + pesan offline, kembali ke login otomatis bila sesi berakhir, lebar konten dibatasi
di web/tablet, tooltip untuk tombol ikon (pembaca layar). MVP feature freeze.
Selesai W11 (security & backup): desain enkripsi NIK/KK final dengan rotasi kunci
([docs/security/field-encryption.md](docs/security/field-encryption.md), `php artisan akur:pii-rotate`), redaksi data
sensitif di semua log (NIK/KK, token, JWT, signature tautan; juga di log akses Caddy), header keamanan + `no-store`
untuk respons API, `php artisan akur:security-check [--production]`, backup offsite terenkripsi age + rclone dan
restore drill otomatis yang mencatat durasi terhadap target RPO 24 jam / RTO 8 jam ([infra/backup](infra/backup/README.md)),
audit dependensi (`infra/security/dependency-audit.sh`), serta [checklist keamanan rilis](docs/security/checklist.md).

Belum: QA & migrasi data uji (W12), deploy otomatis.

## Menjalankan lokal

Prasyarat: PHP 8.3 + ekstensi `pdo_pgsql`, Composer, Docker, Flutter 3.47+.

```bash
docker compose -f compose.dev.yaml up -d
```

```bash
cd backend && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate --seed
```

Isi `GOOGLE_CLIENT_IDS` di `backend/.env` dengan Web client ID, lalu:

```bash
cd backend && php artisan serve
```

```bash
cd app && flutter run -d chrome --web-port 5173 --dart-define=API_BASE_URL=http://localhost:8000 --dart-define=GOOGLE_WEB_CLIENT_ID=<web-client-id>
```

Untuk web lokal, tambahkan `http://localhost:5173` ke `AKUR_WEB_ORIGINS` di `backend/.env`
dan ke *Authorized JavaScript origins* di Google Cloud Console, serta set `AKUR_SESSION_COOKIE_SECURE=false`.

### iOS

Build iOS butuh macOS + Xcode (tidak bisa dari Windows). Bundle ID: `id.my.akur.akur`.

1. Google Cloud Console → buat OAuth client tipe **iOS** dengan bundle ID di atas.
2. Isi `GOOGLE_IOS_CLIENT_ID` dan `GOOGLE_REVERSED_CLIENT_ID` di `app/ios/Flutter/GoogleSignIn.xcconfig`.
   `GOOGLE_CLIENT_IDS` di backend tetap Web client ID (ID token iOS ber-aud Web client ID).
3. Di Mac: `cd app && flutter pub get && cd ios && pod install`, buka `Runner.xcworkspace`, pilih Team untuk signing.
4. Jalankan (Simulator memakai `http://localhost:8000`; perangkat fisik wajib `API_BASE_URL` HTTPS):

```bash
cd app && flutter run -d ios --dart-define=GOOGLE_WEB_CLIENT_ID=<web-client-id>
```

Test backend (memakai database `akur_test` di container dev):

```bash
cd backend && vendor/bin/phpunit
```

> Laragon: `pdo_pgsql` belum aktif di `php.ini`. Aktifkan `extension=pdo_pgsql`, atau jalankan
> `php -d extension=pdo_pgsql vendor/bin/phpunit`.

## Bootstrap pengurus pertama

Belum ada yang bisa memberi peran, jadi pengurus pertama ditetapkan dari server:

1. Buat unit: `php artisan akur:create-unit rw 06 "RW 06"` dan `php artisan akur:create-unit rt 05 "RT 05" --parent=06`
2. Orang tersebut login Google sekali (akun menjadi `pending`).
3. `php artisan akur:grant-role email@gmail.com super_admin 06 --activate`

Semua tercatat di `audit_logs`. Setelah itu penetapan peran dilakukan lewat aplikasi.

## Matriks pemberian peran (FR-03)

| Pemberi | Boleh menetapkan | Cakupan |
|---|---|---|
| super_admin | semua peran | unitnya + RT di bawahnya |
| ketua_rw | ketua_rt, sekretaris, bendahara, warga | RT di bawah RW-nya (di level RW hanya warga) |
| ketua_rt | sekretaris, bendahara, warga | RT-nya |

Penautan akun pending: super_admin, ketua_rw, ketua_rt, sekretaris. Tidak ada yang bisa mengubah peran/akun sendiri.

## Deploy ke VPS (manual, sementara)

```bash
cp .env.example .env && cp backend/.env.production.example backend/.env.production
```

Isi kedua file, salin hasil `flutter build web --release --dart-define=GOOGLE_WEB_CLIENT_ID=...` ke `infra/web-dist/`, lalu:

```bash
docker compose up -d --build && docker compose run --rm api php artisan migrate --force && docker compose run --rm api php artisan db:seed --force --class=Database\\Seeders\\RoleSeeder && docker compose run --rm api php artisan db:seed --force --class=Database\\Seeders\\DocumentTypeSeeder
```

SSL wildcard: lihat [infra/caddy/README.md](infra/caddy/README.md).

## Keputusan & asumsi yang perlu dikonfirmasi

- **Laravel API biasa** dipilih alih-alih Apiato (lebih ringan untuk VPS 2 GB). Kode dikelompokkan per domain.
- Nilai enum yang tidak ditetapkan PRD (`dues_types.frequency`, status kegiatan/rapat, `residency_status`)
  dibiarkan tanpa CHECK sampai disepakati.
- FK melingkar `households.head_resident_id ↔ residents.household_id` dibuat `DEFERRABLE`.
- Satu akun hanya bisa terhubung ke satu data warga aktif (partial unique index).
- Refresh token rotation belum dipakai; kolom `replaced_by_session_id` disiapkan.
