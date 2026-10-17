#!/usr/bin/env bash
# Audit dependensi (W11, PRD §8): dijalankan sebelum setiap rilis dan dicatat di docs/security/checklist.md.
#   - backend: composer audit (database advisori Packagist/FriendsOfPHP)
#   - app:     OSV-Scanner atas pubspec.lock (Dart tidak punya perintah audit bawaan)
# Exit code ≠ 0 bila ada kerentanan yang diketahui.
set -euo pipefail
cd "$(dirname "$0")/../.."

status=0

echo "== backend: composer audit =="
( cd backend && composer audit --no-interaction --locked ) || status=1

echo
echo "== app: OSV-Scanner (pubspec.lock) =="
if command -v osv-scanner >/dev/null; then
    osv-scanner --lockfile=app/pubspec.lock || status=1
else
    echo "osv-scanner tidak terpasang. Pasang dari https://google.github.io/osv-scanner/ lalu jalankan ulang," >&2
    echo "atau jalankan: docker run --rm -v \"\$PWD/app:/src:ro\" ghcr.io/google/osv-scanner:latest --lockfile=/src/pubspec.lock" >&2
    status=1
fi

echo
echo "== app: paket usang (informasi, tidak menggagalkan audit) =="
( cd app && flutter pub outdated --no-dev-dependencies ) || true

exit $status
