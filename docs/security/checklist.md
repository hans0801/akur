# Checklist keamanan rilis AKUR (W11)

Isi setiap rilis ke produksi/pilot. Simpan salinan yang sudah dicentang bersama catatan rilis.

Rilis: `__________`  Tanggal: `__________`  Pemeriksa: `__________`

## 1. Otomatis

- [ ] `docker compose run --rm api php artisan akur:security-check --production` → semua **LULUS**
      (APP_DEBUG mati, HTTPS, cookie Secure, origin HTTPS, LOG_LEVEL, password DB, kunci PII, trigger append-only, redaksi log).
- [ ] `vendor/bin/phpunit` hijau di commit yang dirilis (termasuk uji IDOR lintas RT, peran, masking, rotasi kunci, log).
- [ ] `flutter analyze` bersih dan `flutter test` hijau.
- [ ] `php artisan akur:reconcile-dues` → "Semua tagihan cocok."
- [ ] `infra/security/dependency-audit.sh` → tidak ada kerentanan diketahui (composer audit + OSV-Scanner pubspec.lock).

## 2. Konfigurasi & rahasia

- [ ] `APP_KEY`, `AKUR_PII_ENCRYPTION_KEY`/`AKUR_PII_KEYS`, `AKUR_PII_LOOKUP_KEY`, kunci privat age tersimpan di password
      manager (minimal dua pengurus), **tidak** di repo maupun backup.
- [ ] `.env*` produksi dan kunci tidak ter-commit: `git ls-files | grep -E '\.env($|\.)|\.key$|\.age$'` hanya menampilkan
      berkas `*.example`.
- [ ] `GOOGLE_CLIENT_IDS` hanya Web client ID; OAuth consent screen sudah *In production*.
- [ ] Hanya port 80/443 terbuka di VPS (`ss -tlnp`); PostgreSQL tidak punya port publik.
- [ ] Akses SSH hanya dengan kunci, login root dimatikan, pembaruan keamanan OS terpasang.

## 3. Aplikasi

- [ ] Header keamanan terpasang: `curl -sI https://<domain>/api/v1/me` → `Strict-Transport-Security`, `X-Content-Type-Options`,
      `X-Frame-Options: DENY`, `Cache-Control: no-store`.
- [ ] Tautan cetak surat/laporan kedaluwarsa setelah 5 menit dan menampilkan halaman "Tautan tidak berlaku".
- [ ] NIK/KK tampil tersamar di aplikasi; *reveal* meminta alasan dan muncul di `audit_logs`.
- [ ] Akun pengurus pertama dan peran sudah diperiksa (`user_memberships`), tidak ada super_admin yang tidak dikenal.
- [ ] Rate limit aktif: login (10/menit/IP), API (120/menit/pengguna), endpoint sensitif (30/menit) — `routes/api.php`.
- [ ] Unggahan hanya JPEG/PNG/WebP/PDF ≤ 5 MB (tipe dicek dari isi); berkas terenkripsi di `storage/app/private`;
      service `scheduler` berjalan (`docker compose ps scheduler`) untuk menghapus unggahan yatim.
- [ ] Cookie sesi web `HttpOnly; Secure; SameSite=Strict`, request berubah-data dari browser wajib `X-AKUR-CSRF`
      dan origin terdaftar (uji: `tests/Feature/Auth/SessionTest.php`).

## 4. Log hygiene

- [ ] `docker compose logs api caddy | grep -E '[0-9]{16}|signature=[A-Za-z0-9]|Bearer [A-Za-z0-9]'` → kosong.
- [ ] Rotasi log Docker aktif (json-file `max-size 10m`, `max-file 3` di compose.yaml).
- [ ] `LOG_LEVEL=info` (bukan debug) di produksi.

## 5. Backup & restore

- [ ] Timer backup aktif: `systemctl list-timers akur-backup.timer`; backup terakhir < 24 jam di remote (`rclone lsf`).
- [ ] Restore drill bulan ini **LULUS** (`infra/backup/restore-drill.sh`), ringkasan:

```
tanggal drill   :
backup dipakai  :
umur backup     :            (target RPO ≤ 24 jam)
durasi restore  :            (target RTO ≤ 8 jam; dari log drill)
migrations/users/residents/households/payments/finance_transactions/audit_logs :
berkas privat   :
```

- [ ] Kunci PII yang dihapus dari keyring masih disimpan sampai semua backup yang membutuhkannya kedaluwarsa
      (lihat [field-encryption.md](field-encryption.md)).

## 6. Insiden

- Kontak penanggung jawab teknis: `__________`
- Bila dicurigai kebocoran: cabut sesi (`user_sessions.revoked_at`), rotasi kunci PII ([prosedur](field-encryption.md)),
  ganti password DB, periksa `audit_logs` (aksi `*.nik_revealed`, `*.kk_revealed`, `*.letter_viewed`, `pii.rotated`).
