# syntax=docker/dockerfile:1
# Image Flutter web AKUR: build statis lalu disajikan Caddy di :8080 (TLS di Traefik/Coolify).
#
# Build args (Coolify: tandai sebagai "Build Variable"):
#   API_BASE_URL          mis. https://api-dev.rt05rw06larinda.my.id
#   GOOGLE_WEB_CLIENT_ID  web client ID OAuth (publik, bukan rahasia)

FROM debian:bookworm-slim AS build

ARG FLUTTER_VERSION=3.47.2
ARG FLUTTER_SHA256=447878859d01ca9bfdb99a85f245af07ed8a15fedcd9d189c4749e8e92d1f185

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl git unzip xz-utils \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSLo /tmp/flutter.tar.xz "https://storage.googleapis.com/flutter_infra_release/releases/stable/linux/flutter_linux_${FLUTTER_VERSION}-stable.tar.xz" \
    && echo "${FLUTTER_SHA256}  /tmp/flutter.tar.xz" | sha256sum -c - \
    && tar -xJf /tmp/flutter.tar.xz -C /opt \
    && rm /tmp/flutter.tar.xz \
    && git config --global --add safe.directory /opt/flutter

ENV PATH="/opt/flutter/bin:${PATH}" \
    FLUTTER_SUPPRESS_ANALYTICS=true

RUN flutter config --no-analytics --no-cli-animations >/dev/null && flutter precache --web

WORKDIR /src
COPY pubspec.yaml pubspec.lock ./
RUN flutter pub get

COPY . .
ARG API_BASE_URL
ARG GOOGLE_WEB_CLIENT_ID
RUN test -n "$API_BASE_URL" && test -n "$GOOGLE_WEB_CLIENT_ID" \
    && flutter build web --release \
        --dart-define=API_BASE_URL="$API_BASE_URL" \
        --dart-define=GOOGLE_WEB_CLIENT_ID="$GOOGLE_WEB_CLIENT_ID"

FROM caddy:2-alpine

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY --from=build /src/build/web /srv/web

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1
