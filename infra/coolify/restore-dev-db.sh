#!/bin/sh
# Restore dump lokal ke container Postgres "Development" milik Coolify. Jalankan DI VPS:
#   sh restore-dev-db.sh <container_postgres_dev> <user_db> <nama_db> /tmp/akur-local.dump
# Nama container: `docker ps --format '{{.Names}}' | grep -i postgres` (biasanya UUID dari Coolify).
# PERINGATAN: --clean menghapus tabel yang sudah ada di database tujuan. Jangan arahkan ke Production.
set -eu

if [ "$#" -ne 4 ]; then
  echo "Pakai: $0 <container> <user_db> <nama_db> <berkas.dump>" >&2
  exit 1
fi

CONTAINER="$1"
DB_USER="$2"
DB_NAME="$3"
DUMP="$4"

docker cp "$DUMP" "$CONTAINER":/tmp/akur-restore.dump
docker exec "$CONTAINER" pg_restore -U "$DB_USER" -d "$DB_NAME" \
  --clean --if-exists --no-owner --no-privileges --single-transaction /tmp/akur-restore.dump
docker exec "$CONTAINER" rm -f /tmp/akur-restore.dump

docker exec "$CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -c "select count(*) as jumlah_migrasi from migrations;"
echo "Restore selesai. Hapus berkas dump dari VPS: rm -f $DUMP"
