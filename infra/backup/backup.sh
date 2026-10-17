#!/usr/bin/env bash
# Backup offsite AKUR (W11). Dijalankan di VPS dari root repo, mis. lewat systemd timer/cron harian.
#
#   1. pg_dump (format custom) dari container `db`
#   2. arsip berkas privat (volume akur_api_storage: KTP/KK/bukti bayar bila nanti ada)
#   3. SHA256SUMS → satu tar → dienkripsi dengan age (kunci publik penerima; kunci privat TIDAK ada di server)
#   4. diunggah dengan rclone ke penyimpanan offsite, lalu retensi lokal & remote dibersihkan
#
# Backup hanya berisi ciphertext NIK/KK. Kunci PII, APP_KEY, dan kunci privat age disimpan TERPISAH
# (password manager pengurus), bukan di backup ini. Lihat infra/backup/README.md.
#
# Variabel (di .env root atau environment):
#   BACKUP_AGE_RECIPIENT     kunci publik age (age1...)            — wajib
#   BACKUP_RCLONE_REMOTE     tujuan rclone, mis. b2:akur-backup     — wajib kecuali --local-only
#   BACKUP_DIR               folder lokal (bawaan /var/backups/akur)
#   BACKUP_KEEP_LOCAL_DAYS   retensi lokal (bawaan 7)
#   BACKUP_KEEP_REMOTE_DAYS  retensi remote (bawaan 90)
#
# Opsi: --local-only  simpan terenkripsi di BACKUP_DIR tanpa mengunggah (uji awal).
set -euo pipefail

cd "$(dirname "$0")/../.."
[[ -f .env ]] && { set -a; . ./.env; set +a; }

LOCAL_ONLY=false
[[ "${1:-}" == "--local-only" ]] && LOCAL_ONLY=true

: "${BACKUP_AGE_RECIPIENT:?BACKUP_AGE_RECIPIENT (kunci publik age) wajib diisi}"
$LOCAL_ONLY || : "${BACKUP_RCLONE_REMOTE:?BACKUP_RCLONE_REMOTE wajib diisi (atau pakai --local-only)}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/akur}"
KEEP_LOCAL_DAYS="${BACKUP_KEEP_LOCAL_DAYS:-7}"
KEEP_REMOTE_DAYS="${BACKUP_KEEP_REMOTE_DAYS:-90}"
DB_DATABASE="${DB_DATABASE:-akur}"
DB_USERNAME="${DB_USERNAME:-akur}"
PROJECT="${COMPOSE_PROJECT_NAME:-akur}"

for tool in docker age; do command -v "$tool" >/dev/null || { echo "Butuh $tool" >&2; exit 1; }; done
$LOCAL_ONLY || command -v rclone >/dev/null || { echo "Butuh rclone" >&2; exit 1; }

umask 077
mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

echo "[$(date -u +%FT%TZ)] dump database $DB_DATABASE"
docker compose exec -T db pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" --format=custom --no-owner --no-privileges > "$work/db.dump"
[[ -s "$work/db.dump" ]] || { echo "Dump kosong" >&2; exit 1; }

echo "[$(date -u +%FT%TZ)] arsip berkas privat"
docker run --rm -v "${PROJECT}_api_storage:/data:ro" -v "$work:/out" alpine:3.20 tar -czf /out/storage.tar.gz -C /data .

( cd "$work" && sha256sum db.dump storage.tar.gz > SHA256SUMS )
printf 'created_at=%s\ndatabase=%s\n' "$stamp" "$DB_DATABASE" > "$work/MANIFEST"

archive="$BACKUP_DIR/akur-$stamp.tar.age"
tar -C "$work" -cf - MANIFEST SHA256SUMS db.dump storage.tar.gz | age -r "$BACKUP_AGE_RECIPIENT" -o "$archive"
echo "[$(date -u +%FT%TZ)] terenkripsi: $archive ($(du -h "$archive" | cut -f1))"

if ! $LOCAL_ONLY; then
    rclone copy "$archive" "$BACKUP_RCLONE_REMOTE/" --immutable
    rclone check "$BACKUP_DIR" "$BACKUP_RCLONE_REMOTE/" --include "$(basename "$archive")" --one-way
    rclone delete "$BACKUP_RCLONE_REMOTE/" --min-age "${KEEP_REMOTE_DAYS}d" --include 'akur-*.tar.age'
    echo "[$(date -u +%FT%TZ)] terunggah ke $BACKUP_RCLONE_REMOTE"
fi

find "$BACKUP_DIR" -name 'akur-*.tar.age' -mtime +"$KEEP_LOCAL_DAYS" -delete
echo "[$(date -u +%FT%TZ)] selesai"
