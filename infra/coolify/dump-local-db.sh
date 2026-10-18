#!/bin/sh
# Dump database lokal (compose.dev.yaml) ke berkas format custom pg_dump.
# Jalankan dari root repo (Git Bash di Windows juga bisa):  sh infra/coolify/dump-local-db.sh
# Hasil: akur-local.dump di root repo (sudah di .gitignore lewat pola *.dump — jangan di-commit, berisi PII).
set -eu
# Git Bash di Windows mengubah /tmp/... menjadi path Windows; matikan untuk argumen docker.
export MSYS_NO_PATHCONV=1

OUT="${1:-akur-local.dump}"

docker compose -f compose.dev.yaml exec -T db \
  pg_dump -U akur -d akur --format=custom --no-owner --no-privileges --file=/tmp/akur-local.dump
docker compose -f compose.dev.yaml cp db:/tmp/akur-local.dump "$OUT"
docker compose -f compose.dev.yaml exec -T db rm -f /tmp/akur-local.dump

echo "Selesai: $OUT"
echo "Kirim ke VPS:  scp $OUT user@IP_VPS:/tmp/"
