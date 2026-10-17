# Caddy & SSL

## Default: sertifikat per host (HTTP-01)

`Caddyfile` menerbitkan sertifikat Let's Encrypt untuk `APP_DOMAIN` secara otomatis.
Syarat: DNS `A`/`AAAA` record domain mengarah ke IP VPS dan port 80/443 terbuka.

## Wildcard `*.rt05rw06larinda.my.id` (permintaan PRD §8)

Sertifikat wildcard **hanya** bisa lewat DNS-01 challenge, jadi Caddy perlu plugin
untuk penyedia DNS domain Anda dan API token-nya. Ini belum dikonfigurasi karena
penyedia DNS belum diputuskan.

Langkah bila DNS di Cloudflare (contoh):

1. Buat image Caddy dengan plugin DNS:

   ```dockerfile
   FROM caddy:2.10-builder AS builder
   RUN xcaddy build --with github.com/caddy-dns/cloudflare
   FROM caddy:2.10-alpine
   COPY --from=builder /usr/bin/caddy /usr/bin/caddy
   ```

2. Buat API token Cloudflare dengan izin *Zone → DNS → Edit* hanya untuk zona tersebut,
   simpan sebagai `CF_API_TOKEN` di `.env` VPS (jangan di Git).

3. Ganti blok situs:

   ```caddyfile
   *.rt05rw06larinda.my.id, rt05rw06larinda.my.id {
   	tls {
   		dns cloudflare {env.CF_API_TOKEN}
   	}
   	@app host rt05rw06larinda.my.id
   	handle @app {
   		# isi sama dengan blok {$APP_DOMAIN} di Caddyfile
   	}
   	handle {
   		abort
   	}
   }
   ```

Penyedia lain (Niagahoster, IDCloudHost, dll.) perlu modul `caddy-dns/<provider>` yang sesuai —
cek ketersediaannya di https://github.com/caddy-dns.
