# syntax=docker/dockerfile:1
# Image API AKUR: Laravel di FrankenPHP, hanya mendengarkan di jaringan Docker internal (:8080).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

FROM dunglas/frankenphp:1-php8.3-alpine

RUN install-php-extensions pdo_pgsql intl opcache pcntl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-akur.ini
COPY docker/entrypoint.sh /usr/local/bin/akur-entrypoint

WORKDIR /app
COPY --from=vendor /app /app

# Jalan sebagai user non-root; port 8080 tidak butuh capability tambahan.
RUN adduser -D -u 10001 akur \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /app/storage/framework/cache /app/storage/framework/views /app/storage/logs /app/storage/app/private \
    && chown -R akur:akur /app/storage /app/bootstrap/cache /config/caddy /data/caddy \
    && chmod +x /usr/local/bin/akur-entrypoint
USER akur

ENV SERVER_NAME=:8080 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1:8080/up >/dev/null || exit 1

ENTRYPOINT ["akur-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
