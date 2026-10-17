# Desain enkripsi field NIK/KK (W11)

Status: **diterapkan** (W5 dasar, W11 rotasi kunci). Menutup keputusan terbuka "desain enkripsi NIK/KK" di PRD.

## Tujuan dan ancaman

| Ancaman | Mitigasi |
|---|---|
| Dump/backup database bocor | NIK/KK hanya tersimpan sebagai ciphertext AEAD; kunci tidak ada di database maupun backup. |
| Akses baca SQL oleh pihak tak berwenang (mis. SQL injection, kredensial DB bocor) | Sama seperti di atas; pencarian memakai HMAC berkunci, bukan hash polos (tidak bisa ditebak dengan brute force 16 digit tanpa kunci). |
| Ciphertext dipindah antar kolom (NIK ↔ KK) | Nama field (`residents.nik`, `households.kk_number`) dipakai sebagai associated data; dekripsi gagal bila dipindah. |
| Kebocoran lewat log, audit, notifikasi, URL | AuditLogger menyaring kunci sensitif; log disaring `RedactSensitiveData`; pencarian NIK lewat POST; NIK utuh hanya lewat endpoint *reveal* beralasan yang diaudit. |
| Kunci lama bocor | Rotasi: kunci baru + `akur:pii-rotate`, lalu kunci lama dihapus dari keyring. |

Di luar cakupan: penyerang yang menguasai server aplikasi saat berjalan (memegang kunci di memori). Mitigasinya
operasional: patch rutin, akses SSH minimal, `akur:security-check` sebelum rilis.

## Algoritma

- **Enkripsi:** XChaCha20-Poly1305 (libsodium, AEAD), nonce acak 192-bit per nilai, AD = nama field.
- **Pencarian exact-match:** `HMAC-SHA256(lookup_key, field ‖ 0x00 ‖ nilai_ternormalisasi)`, disimpan di
  `*_lookup_hmac` dengan indeks unik. Kunci lookup **terpisah** dari kunci enkripsi.
- **Masking:** hanya 4 digit terakhir dikirim ke klien (`************1234`).

## Format ciphertext

| Versi | Tata letak | Catatan |
|---|---|---|
| v1 (W5) | `0x01 ‖ nonce(24) ‖ ct+tag` | Selalu kunci id 1. Tetap bisa dibaca. |
| v2 (W11) | `0x02 ‖ key_id(1) ‖ nonce(24) ‖ ct+tag` | Semua tulisan baru. `key_id` 1–255. |

## Kunci

| Variabel | Isi |
|---|---|
| `AKUR_PII_ENCRYPTION_KEY` | Kunci 32 byte (base64), dibaca sebagai **id 1** (kompatibel W5). |
| `AKUR_PII_KEYS` | Keyring opsional: `1:base64...,2:base64...`. |
| `AKUR_PII_ACTIVE_KEY_ID` | Id kunci untuk enkripsi baru (bawaan: id terbesar). |
| `AKUR_PII_LOOKUP_KEY` | Kunci HMAC 32 byte, wajib berbeda dari semua kunci enkripsi. |

Buat kunci: `php -r "echo base64_encode(random_bytes(32));"`. Simpan semua kunci (dan `APP_KEY`) di password manager
pengurus, **terpisah dari backup**. Tanpa kunci, backup tidak bisa dipakai untuk membaca NIK/KK — itu disengaja,
tetapi berarti kehilangan kunci = kehilangan NIK/KK.

## Prosedur rotasi kunci enkripsi

1. Buat kunci baru, simpan di password manager.
2. Set `AKUR_PII_KEYS="1:<lama>,2:<baru>"` dan `AKUR_PII_ACTIVE_KEY_ID=2`, deploy ulang (`config:cache`).
   Sejak saat ini data baru memakai kunci 2; data lama tetap terbaca.
3. `php artisan akur:pii-rotate --dry-run` lalu `php artisan akur:pii-rotate` (idempoten, per 200 baris per transaksi, diaudit).
4. Ulangi `--dry-run` sampai 0 baris, jalankan backup baru, lalu hapus kunci 1 dari keyring dan deploy ulang.
5. Backup lama yang dibuat sebelum langkah 4 masih membutuhkan kunci 1: simpan kunci 1 sampai backup tersebut kedaluwarsa
   (bawaan retensi 90 hari), baru hapus permanen.

## Prosedur rotasi kunci lookup

Mengganti `AKUR_PII_LOOKUP_KEY` membuat semua HMAC lama tidak cocok (cek duplikat dan pencarian NIK berhenti bekerja)
sampai dihitung ulang. Lakukan dalam jendela pemeliharaan:

1. Aktifkan mode pemeliharaan (`php artisan down`).
2. Ganti `AKUR_PII_LOOKUP_KEY`, deploy ulang.
3. `php artisan akur:pii-rotate --all` (dekripsi → enkripsi ulang → HMAC baru).
4. `php artisan up`.

## Risiko yang diterima

- AD belum mengikat ciphertext ke **baris** (id warga). Penyerang dengan akses tulis DB bisa menukar NIK dua warga
  dalam kolom yang sama. Akses tulis DB sudah berarti kompromi penuh; perubahan format (v3, AD = field ‖ id) bisa
  ditambahkan tanpa migrasi besar karena versi ciphertext tersimpan.
- Kunci disimpan di environment server, belum di KMS/HSM. Antarmuka `PiiCipher` memungkinkan implementasi KMS
  (envelope encryption) menggantikan `SodiumPiiCipher` tanpa mengubah pemanggil.
