#!/usr/bin/env bash
# Restore drill AKUR (W11): buktikan backup bisa dipulihkan, tanpa menyentuh database produksi.
#
#   restore-drill.sh                 ambil backup terbaru dari BACKUP_RCLONE_REMOTE
#   restore-drill.sh FILE.tar.age    pakai berkas terenkripsi lokal (butuh BACKUP_AGE_IDENTITY)
#   restore-drill.sh FILE.tar        berkas tidak terenkripsi (hanya untuk uji lokal/dev)
#   --keep                           jangan hapus container hasil restore (untuk diperiksa manual)
#
# Langkah: unduh → dekripsi → cek SHA256 → restore ke container postgres:17 sementara (tanpa port publik)
# → jalankan drill-checks.sql → cek arsip berkas → catat hasil ke $BACKUP_DIR/restore-drill.log.
# BACKUP_AGE_IDENTITY: path kunci privat age. Bawa dari penyimpanan offline hanya selama drill.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
cd "$here/../.."
[[ -f .env ]] && { set -a; . ./.env; set +a; }

KEEP=false
SOURCE=""
for arg in "$@"; do
    case "$arg" in
        --keep) KEEP=true ;;
        *) SOURCE="$arg" ;;
    esac
done

BACKUP_DIR="${BACKUP_DIR:-/var/backups/akur}"
mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
work="$(mktemp -d)"
container="akur-restore-drill-$stamp"
cleanup() {
    rm -rf "$work"
    $KEEP || docker rm -f "$container" >/dev/null 2>&1 || true
}
trap cleanup EXIT

if [[ -z "$SOURCE" ]]; then
    : "${BACKUP_RCLONE_REMOTE:?BACKUP_RCLONE_REMOTE wajib diisi atau berikan berkas backup}"
    latest="$(rclone lsf "$BACKUP_RCLONE_REMOTE/" --include 'akur-*.tar.age' | sort | tail -n 1)"
    [[ -n "$latest" ]] || { echo "Tidak ada backup di remote" >&2; exit 1; }
    rclone copy "$BACKUP_RCLONE_REMOTE/$latest" "$work/"
    SOURCE="$work/$latest"
fi

echo "[drill] sumber: $(basename "$SOURCE")"
case "$SOURCE" in
    *.age)
        : "${BACKUP_AGE_IDENTITY:?BACKUP_AGE_IDENTITY (path kunci privat age) wajib untuk berkas .age}"
        age -d -i "$BACKUP_AGE_IDENTITY" "$SOURCE" | tar -x -C "$work" ;;
    *.tar)
        tar -x -C "$work" -f "$SOURCE" ;;
    *) echo "Format tidak dikenal: $SOURCE" >&2; exit 1 ;;
esac

( cd "$work" && sha256sum -c SHA256SUMS )

echo "[drill] restore ke container sementara $container"
docker run -d --name "$container" -e POSTGRES_USER=akur -e POSTGRES_PASSWORD=drill-only -e POSTGRES_DB=akur_restore postgres:17-alpine >/dev/null
for _ in $(seq 1 60); do
    docker exec "$container" pg_isready -U akur -d akur_restore >/dev/null 2>&1 && break
    sleep 1
done
docker exec -i "$container" pg_restore -U akur -d akur_restore --no-owner --no-privileges --exit-on-error < "$work/db.dump"

echo "[drill] pemeriksaan integritas"
docker exec -i "$container" psql -U akur -d akur_restore -v ON_ERROR_STOP=1 -q < "$here/drill-checks.sql" | tee "$work/report.txt"

files="$(tar -tzf "$work/storage.tar.gz" | grep -vc '/$' || true)"
echo "[drill] berkas privat dalam arsip: $files"

{
    echo "== restore drill $stamp =="
    echo "backup: $(basename "$SOURCE")"
    cat "$work/MANIFEST" 2>/dev/null || true
    cat "$work/report.txt"
    echo "berkas privat: $files"
    echo "hasil: LULUS"
} >> "$BACKUP_DIR/restore-drill.log"

echo "[drill] LULUS — dicatat di $BACKUP_DIR/restore-drill.log"
$KEEP && echo "[drill] container $container dibiarkan berjalan (hapus: docker rm -f $container)"
