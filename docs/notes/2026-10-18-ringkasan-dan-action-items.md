# Ringkasan kerja 18 Oktober 2026 & action items

Catatan sesi pengembangan AKUR (backend Laravel, aplikasi Flutter, dan infrastruktur dev di VPS + Cloudflare).
Rahasia (token, kunci, IP publik, port SSH) sengaja tidak dicatat di sini; lokasinya hanya di mesin pemilik.

## Ringkasan

| # | Pekerjaan | Hasil | Commit |
|---|-----------|-------|--------|
| 1 | Avatar akun, halaman pemeliharaan, mode pemeliharaan | Selesai, live di dev | `c85b366` |
| 2 | Deploy ke VPS (API + web) dan aplikasi Android di emulator | Selesai | — |
| 3 | Cache web Flutter di Cloudflare | Selesai | `3e83150` |
| 4 | Deploy otomatis web dari GitHub Actions ke Coolify | Selesai | `42d111e` |
| 5 | Penyimpanan berkas privat ke Cloudflare R2 | Selesai, berkas lama sudah dimigrasi | `c95564c`, `e4b322f` |
| 6 | SSL: Cloudflare Origin Certificate + Full (strict), edge cert Google Trust Services | Selesai | `71bc669` |
| 7 | Pengamanan VPS: origin hanya lewat Cloudflare, SSH key-only, fail2ban, SSH via Tunnel + Access + WARP | Selesai | `1532da7` |

## 1. Fitur aplikasi (`c85b366`)

**Avatar akun**
- Kolom `users.avatar_choice` + `PUT /api/v1/me/avatar`. Pilihan: 8 ilustrasi "Harmoni Warga" (`warga-1`..`warga-8`,
  digambar vektor di Flutter tanpa aset), foto Google, atau inisial. Tanpa pilihan = ilustrasi bawaan tetap per akun.
- Tampil di header, beranda, dan lembar profil. Dipilih dari menu baru **Pengaturan aplikasi**.

**Mode pemeliharaan**
- Tabel `app_settings`; `GET /api/v1/admin/settings` dan `PUT /api/v1/admin/settings/maintenance` hanya untuk super_admin.
- Middleware `not.maintenance`: selain super_admin mendapat **503 `code: maintenance`**. Login, `/me`, dan logout tetap
  terbuka (super_admin bisa login untuk mematikannya). `/me` dan respons login membawa status + pesan pemeliharaan.
- Flutter: halaman pemeliharaan berilustrasi (mode pemeliharaan atau server tak terjangkau/5xx/timeout), coba lagi
  otomatis tiap 30 detik; strip peringatan untuk super_admin saat mode aktif; saklar di Pengaturan aplikasi.
- Tes: `MaintenanceModeTest`, `AvatarTest`.

## 2–4. Deploy dan CI/CD

- Push ke `main` memicu: API (Coolify, watch path `backend/**`, migrasi di post-deploy) dan web (GitHub Actions →
  GHCR → Coolify).
- Cloudflare menyimpan `main.dart.js` 4 jam → web lama tetap tampil. Perbaikan: Caddy mengirim `no-cache` untuk berkas
  entry Flutter (`3e83150`), Cloudflare *Browser Cache TTL = Respect Existing Headers*, purge sekali.
- Langkah "Deploy di Coolify" di workflow gagal 403 karena **Bot Fight Mode** menantang IP GitHub. Bot Fight Mode
  dimatikan; langkah itu kini menampilkan respons Coolify bila gagal (`42d111e`).
- Deploy API sempat macet di antrean Coolify; dibatalkan dan dijalankan ulang lewat API Coolify.

## 5. Cloudflare R2 (`c95564c`)

- Disk `r2` (S3-compatible). `AKUR_FILES_DISK=r2`, `AKUR_FILES_FALLBACK_DISK=local` selama masa pindah.
- Isi berkas tetap dienkripsi APP_KEY + checksum SHA-256; unduhan tetap lewat tautan bertanda tangan API.
- Perintah `php artisan akur:files-migrate` (idempoten, verifikasi checksum, sumber tidak dihapus) dijalankan di
  post-deploy. Berkas lama dev sudah tersalin ke folder `akur-storage/dev-private`.
- Unggahan tanda tangan/logo dibatasi 1200 px di perangkat (foto bukti/nota sudah 1600 px, JPEG 80).
- Ekspor laporan xlsx ditunda.

## 6. SSL (`71bc669`)

- Traefik memakai **Cloudflare Origin Certificate** (`*.rt05rw06larinda.my.id`, berlaku s.d. 17 Okt 2029); sertifikat
  Let's Encrypt lama dihapus dari `acme.json` (ada cadangan).
- Cloudflare: **Full (strict)**, Always Use HTTPS, Minimum TLS 1.2. Edge cert Universal SSL diganti ke
  **Google Trust Services** lewat API.

## 7. Pengamanan VPS (`1532da7`)

- VPS di balik NAT provider; firewall berbasis IP Cloudflare tidak bisa dipakai untuk HTTP.
- **Authenticated Origin Pulls**: Traefik hanya menerima HTTPS bersertifikat klien Cloudflare; akses langsung ke IP
  ditolak saat handshake.
- **SSH**: key-only, root hanya dengan key, MaxAuthTries 3; **fail2ban** (mode aggressive, unit `ssh.service`).
- **Cloudflare Tunnel** `svr-p-main-01` + **Access** (verifikasi email):
  - PC: `ssh akur-vps` (cloudflared `access ssh`).
  - HP: aplikasi Cloudflare One (WARP) + private network route ke IP privat VPS, lalu Termius dengan key khusus HP.
- Cloudflare: Security Level Medium, rate limiting `/api/v1/auth/`. Laravel sudah membatasi 10 req/menit.

Detail teknis dan langkah ulang ada di [`infra/coolify/README.md`](../../infra/coolify/README.md) bagian 6–8.

## Pelajaran

- **Under Attack Mode / challenge** di Cloudflare mematikan API untuk aplikasi mobile (tidak bisa menyelesaikan
  challenge). Jangan nyalakan untuk `api-dev`.
- **Bot Fight Mode** memblokir GitHub Actions (IP pusat data) dan tidak bisa dikecualikan di paket Free.
- Traefik mendahulukan sertifikat dengan nama persis (ACME) dibanding wildcard; hapus sertifikat ACME lama setelah
  memasang Origin Certificate.
- `main.dart.js` Flutter tidak ber-hash; harus `no-cache` agar deploy baru langsung terpakai.
- Di Ubuntu 24.04 unit SSH bernama `ssh.service`; filter fail2ban bawaan mencari `sshd.service`.

## Action items

### Segera
- [ ] Tutup port forward SSH publik di panel provider (tunnel di PC dan Termius sudah terbukti jalan); setelah itu
      hapus entri `akur-vps-direct` dari `~/.ssh/config`.
- [ ] Hapus token yang tidak dipakai lagi dari folder token lokal (token tunnel; token SSL Cloudflare sudah dihapus).
- [ ] Uji R2 dari ujung ke ujung di aplikasi: unggah bukti bayar / nota, terbitkan surat, buka berkasnya (web + Android).
- [ ] Uji fitur avatar dan mode pemeliharaan dengan akun super_admin dan warga di dev.

### Berikutnya
- [ ] SSH lewat browser (Access browser rendering + short-lived certificates): aktifkan *Browser rendering: SSH*,
      buat SSH CA di Zero Trust, lalu pasang `TrustedUserCAKeys` + `AuthorizedPrincipalsFile` di VPS.
- [ ] Setelah beberapa hari stabil: hapus `AKUR_FILES_FALLBACK_DISK` dan volume `akur-dev-private` di Coolify.
- [ ] Sebelum produksi: token R2 terpisah per lingkungan (atau bucket prd sendiri); token dev saat ini bisa
      menjangkau folder prd di bucket yang sama.
- [ ] Backup: arahkan `infra/backup` (rclone) dan backup database Coolify ke R2; uji restore.
- [ ] Ekspor laporan xlsx (OpenSpout) — ditunda.
- [ ] Perpendek durasi sesi aplikasi Access SSH (mis. 1–2 jam).

### Pemeliharaan
- [ ] Perbarui GitHub Actions ke versi berbasis Node.js 24 (`actions/checkout`, docker actions) dan antisipasi
      `ubuntu-latest` → Ubuntu 26.
- [ ] Origin Certificate berakhir **17 Okt 2029**: buat baru dan ganti file di `/data/coolify/proxy/certs/`.
- [ ] `laravel/boost` (diminta `backend/CLAUDE.md`) belum dipasang.
