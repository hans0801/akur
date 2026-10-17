#!/bin/sh
set -e

# Cache konfigurasi dari environment runtime. Migrasi TIDAK dijalankan otomatis;
# jalankan eksplisit saat deploy: docker compose run --rm api php artisan migrate --force
php artisan config:cache
php artisan route:cache

exec docker-php-entrypoint "$@"
