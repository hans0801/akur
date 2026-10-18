# Deploy environment Development ke VPS (Coolify + Traefik)

Target: API Laravel di **https://api-dev.rt05rw06larinda.my.id**, database di container Postgres "Development"
yang sudah dibuat di Coolify. Traefik (proxy bawaan Coolify) yang menerbitkan sertifikat Let's Encrypt dan
meneruskan trafik ke container API (port 8080). `compose.yaml` + Caddy tidak dipakai di Coolify.

| Komponen | Nilai |
|---|---|
| API dev | `https://api-dev.rt05rw06larinda.my.id` (Laravel/FrankenPHP, port 8080, health `/up`) |
| Web dev (nanti) | `https://dev.rt05rw06larinda.my.id` (Flutter web) |
| Database | Postgres "Development" di Coolify, tanpa port publik |
| Env | [dev.env.example](dev.env.example) |

## 1. DNS

Traefik bukan DNS server: record dibuat di pengelola DNS domain `rt05rw06larinda.my.id` (registrar/Cloudflare).

| Tipe | Nama | Nilai |
|---|---|---|
| A | `api-dev` | IP publik VPS |
| A | `dev` | IP publik VPS (untuk Flutter web nanti) |

Kalau memakai Cloudflare, set **DNS only** (awan abu-abu) dulu supaya Let's Encrypt HTTP-01 lewat Traefik berhasil.
Cek: `nslookup api-dev.rt05rw06larinda.my.id` harus mengembalikan IP VPS. Port 80 dan 443 harus terbuka di firewall VPS.

## 2. Database Postgres Development

Di Coolify → database Postgres Development:

1. Pastikan image **postgres:17** (sama dengan lokal; `pg_restore` butuh versi tujuan ≥ versi dump).
2. **Make it publicly available: OFF.** API mengakses lewat jaringan Docker internal.
3. Catat *Postgres URL (internal)*: `postgres://USER:PASSWORD@HOST:5432/DB`. `HOST` = nama container (UUID).
4. Kalau database awalnya `postgres`, buat database `akur` dari menu Terminal container DB:
   `createdb -U <USER> akur`

## 3. Aplikasi API di Coolify

**+ New → Application** pada project & environment yang sama dengan database Development (server yang sama):

| Pengaturan | Nilai |
|---|---|
| Source | Private repository (GitHub App) → `hans0801/akur`, branch `main` |
| Build Pack | **Dockerfile** |
| Base Directory | `/backend` |
| Dockerfile Location | `/Dockerfile` |
| Ports Exposes | `8080` |
| Domains | `https://api-dev.rt05rw06larinda.my.id` |
| Health Check | aktif, path `/up`, port `8080` |

**Environment Variables:** tempel isi [dev.env.example](dev.env.example) (Developer view), isi `APP_KEY`, kredensial DB dari langkah 2,
`GOOGLE_CLIENT_IDS`, dan kedua kunci PII. Variabel cukup *runtime* (tidak perlu "Build Variable").

**Persistent Storage:** Add → Volume, nama `akur-dev-private`, destination path `/app/storage/app/private`
(bukti bayar, PDF surat, logo — terenkripsi APP_KEY).

**Post-deployment Command** (jalan di dalam container setiap deploy; seeder ini idempotent):

```bash
php artisan migrate --force && php artisan db:seed --force --class=RoleSeeder && php artisan db:seed --force --class=DocumentTypeSeeder
```

**Scheduled Tasks** (pengganti service `scheduler`): name `laravel-scheduler`, command `php artisan schedule:run`, frequency `* * * * *`.

Klik **Deploy**. Auto-deploy setiap push ke `main` aktif otomatis bila memakai GitHub App.

> Kalau log deploy menunjukkan API tidak bisa resolve host database, aktifkan **Connect to Predefined Network**
> di pengaturan aplikasi, atau pastikan aplikasi dan database berada di server/destination yang sama.

## 4. Isi database dev

Pilih salah satu.

**A. Skema bersih (disarankan).** Sudah terjadi otomatis lewat post-deployment command. Lalu bootstrap pengurus
dari menu **Terminal** container API:

```bash
php artisan akur:create-unit rw 06 "RW 06" && php artisan akur:create-unit rt 05 "RT 05" --parent=06
```

Login Google sekali dari aplikasi (akun jadi `pending`), lalu:

```bash
php artisan akur:grant-role email@gmail.com super_admin 06 --activate
```

**B. Salin data lokal ke dev.** Syarat: `APP_KEY`, `AKUR_PII_ENCRYPTION_KEY`, dan `AKUR_PII_LOOKUP_KEY` di Coolify
**sama persis** dengan `backend/.env` lokal — kalau beda, NIK/KK tidak bisa didekripsi dan pencarian NIK gagal.

Di laptop (Docker Desktop menyala, dari root repo):

```bash
sh infra/coolify/dump-local-db.sh
```

```bash
scp akur-local.dump infra/coolify/restore-dev-db.sh user@IP_VPS:/tmp/
```

Di VPS (ganti nama container/user dari langkah 2; **jangan** container Production):

```bash
sh /tmp/restore-dev-db.sh <container_postgres_dev> <USER> akur /tmp/akur-local.dump
```

Berkas unggahan lokal (`backend/storage/app/private`) tidak ikut dump; salin manual ke volume bila perlu.
Hapus `akur-local.dump` dari laptop dan VPS setelah selesai (berisi PII).

## 5. Verifikasi

```bash
curl -i https://api-dev.rt05rw06larinda.my.id/up
```

Dari Terminal container API: `php artisan akur:security-check` dan `php artisan migrate:status`.

## 6. Menghubungkan aplikasi Flutter

```bash
cd app && flutter run --dart-define=API_BASE_URL=https://api-dev.rt05rw06larinda.my.id --dart-define=GOOGLE_WEB_CLIENT_ID=<web-client-id>
```

Untuk Flutter web di `https://dev.rt05rw06larinda.my.id`, tambahkan origin itu ke *Authorized JavaScript origins*
di Google Cloud Console (sudah ada di `AKUR_WEB_ORIGINS`). Web lokal `http://localhost:5173` tidak bisa memakai cookie
sesi API dev (cross-site + Secure); pakai API lokal untuk itu.

## Catatan

- Memori: Coolify ±0,5–1 GB, dua Postgres, API (`memory_limit` PHP 192M). Muat di 4 GB; pantau di Coolify → Server → Metrics.
- Production nanti: aplikasi kedua dari branch/tag terpisah, domain `rt05rw06larinda.my.id`, DB Production,
  `APP_ENV=production`, `LOG_LEVEL=info`, kunci berbeda dari dev, lalu `php artisan akur:security-check --production`.
- Backup Postgres dev bisa diatur di Coolify → database → Backups; backup offsite produksi tetap [infra/backup](../backup/README.md).
