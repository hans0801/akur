# Backup offsite & restore drill (W11)

Target PRD: **RPO 24 jam** (backup harian) dan **RTO 8 jam** (layanan pulih di VPS baru). Keduanya harus *teruji*:
restore drill **bulanan** mencatat durasi restore, dan umur backup terbaru dicek di checklist rilis.
Penyimpanan offsite memakai **akun penyedia yang terpisah** dari penyedia VPS, agar satu akun yang bobol atau
diblokir tidak menghapus server sekaligus backupnya.

## Apa yang dibackup

| Isi | Cara | Catatan |
|---|---|---|
| Database PostgreSQL | `pg_dump --format=custom` | NIK/KK tetap ciphertext di dalam dump. |
| Berkas privat (`akur_api_storage`) | tar | KTP/KK/bukti bayar bila fitur unggah aktif. |
| **Tidak** dibackup di sini | — | `.env`, `backend/.env.production`, `APP_KEY`, kunci PII, kunci privat age. Simpan di password manager pengurus. |

Setiap backup = satu berkas `akur-<waktu UTC>.tar.age` berisi `MANIFEST`, `SHA256SUMS`, `db.dump`, `storage.tar.gz`,
dienkripsi dengan [age](https://age-encryption.org) memakai **kunci publik**. Server tidak pernah memegang kunci privat,
jadi server yang dibobol tidak bisa membuka backup lama.

## Persiapan sekali

1. Di laptop pengurus (bukan VPS): `age-keygen -o akur-backup.key`. Simpan berkas ini di password manager
   (dua orang pengurus). Catat baris `public key: age1...`.
2. Di VPS: pasang `age` dan `rclone`, lalu `rclone config` untuk penyimpanan offsite (mis. Backblaze B2 / S3-kompatibel
   / Google Drive akun organisasi). Gunakan bucket khusus dengan *object lock* atau versi bila tersedia.
3. Tambahkan ke `.env` root di VPS:

   ```
   BACKUP_AGE_RECIPIENT=age1...
   BACKUP_RCLONE_REMOTE=b2:akur-backup
   BACKUP_DIR=/var/backups/akur
   ```

4. Uji: `infra/backup/backup.sh --local-only`, lalu tanpa `--local-only`.
5. Jadwalkan harian (contoh systemd, sesuaikan path repo):

   ```ini
   # /etc/systemd/system/akur-backup.service
   [Service]
   Type=oneshot
   WorkingDirectory=/opt/akur
   ExecStart=/opt/akur/infra/backup/backup.sh

   # /etc/systemd/system/akur-backup.timer
   [Timer]
   OnCalendar=*-*-* 02:30:00 Asia/Jakarta
   Persistent=true
   [Install]
   WantedBy=timers.target
   ```

   `systemctl enable --now akur-backup.timer`. Pantau `journalctl -u akur-backup` (gagal = tidak ada backup!).

## Restore drill (bulanan)

Membuktikan backup terbaru bisa dipulihkan **tanpa** menyentuh database produksi:

```bash
BACKUP_AGE_IDENTITY=/root/akur-backup.key infra/backup/restore-drill.sh
shred -u /root/akur-backup.key
```

Script mengunduh backup terbaru, mendekripsi, memeriksa SHA256, me-restore ke container `postgres:17` sementara
(tanpa port publik), lalu menjalankan `drill-checks.sql`:

- migrasi ada; trigger append-only `audit_logs` dan `finance_transactions` ikut terpulihkan;
- semua NIK/KK masih ciphertext berversi (bukan teks polos);
- `amount_paid` iuran cocok dengan pembayaran terverifikasi; tidak ada transaksi pembalik yatim;
- ringkasan jumlah baris per tabel.

Hasil dicatat di `$BACKUP_DIR/restore-drill.log` termasuk **durasi** dan apakah masih dalam target RTO
(`RESTORE_RTO_SECONDS`, bawaan 8 jam); salin ringkasannya ke checklist rilis
([docs/security/checklist.md](../../docs/security/checklist.md)). Container dihapus otomatis (`--keep` untuk memeriksa).

## Restore sungguhan (bencana)

1. Siapkan VPS baru sesuai README utama, **tanpa** menjalankan migrasi.
2. Pulihkan `.env` dan `backend/.env.production` dari password manager (APP_KEY dan kunci PII **harus sama**).
3. `docker compose up -d db`, lalu dekripsi backup dan restore:

   ```bash
   age -d -i akur-backup.key akur-<waktu>.tar.age | tar -x -C /tmp/akur-restore
   (cd /tmp/akur-restore && sha256sum -c SHA256SUMS)
   docker compose exec -T db pg_restore -U akur -d akur --clean --if-exists --no-owner < /tmp/akur-restore/db.dump
   docker run --rm -v akur_api_storage:/data -v /tmp/akur-restore:/in alpine:3.20 tar -xzf /in/storage.tar.gz -C /data
   ```

4. `docker compose up -d`, lalu `docker compose run --rm api php artisan migrate --force` (bila kode lebih baru),
   `php artisan akur:security-check --production`, dan `php artisan akur:reconcile-dues`.
5. Hapus `/tmp/akur-restore` dan kunci privat dari server.
