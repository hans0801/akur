<?php

namespace App\Services\Files;

use App\Models\DocumentRequest;
use App\Models\FileObject;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\UnitLetterSetting;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Penyimpanan berkas privat (W8/W9). Isi dienkripsi dengan APP_KEY sebelum ditulis ke disk (volume VPS atau
 * Cloudflare R2, lihat config akur.files) sehingga salinan penyimpanan tidak membuka bukti bayar, nota, atau
 * surat ber-NIK; checksum SHA-256 isi asli diverifikasi saat dibaca.
 * Berkas diunggah dulu, lalu "diklaim" oleh pembayaran/transaksi kas milik pengunggah yang sama.
 */
class PrivateFiles
{
    private const KEY_VERSION = 'app-key';

    public function store(UploadedFile $upload, User $owner, string $classification): FileObject
    {
        $content = $upload->get();
        if ($content === false || $content === '') {
            throw ValidationException::withMessages(['file' => 'Berkas kosong atau tidak terbaca.']);
        }

        // Tipe dari isi berkas (finfo), sudah divalidasi aturan `mimetypes` di controller.
        return $this->storeContent($content, $upload->getMimeType(), basename($upload->getClientOriginalName()), $classification, $owner);
    }

    /** Simpan isi yang dibuat server (mis. PDF surat) dengan enkripsi dan checksum yang sama seperti unggahan. */
    public function storeContent(string $content, string $mimeType, string $filename, string $classification, ?User $owner = null): FileObject
    {
        $key = $classification.'/'.now()->format('Y/m').'/'.Str::uuid();
        Storage::disk(config('akur.files.disk'))->put($key, Crypt::encryptString($content));

        $file = new FileObject([
            'owner_user_id' => $owner?->id,
            'storage_key' => $key,
            'original_filename' => Str::limit($filename, 200, ''),
            'mime_type' => $mimeType,
            'byte_size' => strlen($content),
            'classification' => $classification,
            'encryption_key_version' => self::KEY_VERSION,
        ]);
        $file->setAttribute('checksum_sha256', DB::raw("decode('".hash('sha256', $content)."', 'hex')"));
        $file->save();

        return $file->refresh();
    }

    /** Isi asli berkas; gagal bila berkas di disk berubah (checksum tidak cocok). */
    public function read(FileObject $file): string
    {
        $stored = $this->readStored($file);
        if ($stored === null) {
            throw new RuntimeException('Berkas tidak ditemukan di penyimpanan.');
        }

        return $this->decryptVerified($file, $stored);
    }

    /** Isi terenkripsi apa adanya: dari disk utama, lalu disk cadangan (masa pindah ke R2). */
    public function readStored(FileObject $file): ?string
    {
        foreach ($this->disks() as $disk) {
            if ($disk->exists($file->storage_key)) {
                return $disk->get($file->storage_key);
            }
        }

        return null;
    }

    /** Dekripsi isi tersimpan dan cocokkan dengan checksum SHA-256 isi asli. */
    public function decryptVerified(FileObject $file, string $stored): string
    {
        $content = Crypt::decryptString($stored);

        $expected = DB::table('file_objects')->where('id', $file->id)->selectRaw("encode(checksum_sha256, 'hex') AS h")->value('h');
        if (! hash_equals((string) $expected, hash('sha256', $content))) {
            throw new RuntimeException('Checksum berkas tidak cocok.');
        }

        return $content;
    }

    /**
     * Pastikan berkas boleh dilampirkan: milik pengguna ini, klasifikasi sesuai, dan belum dipakai entitas lain.
     */
    public function claim(?string $fileId, User $actor, string $classification, string $field): ?FileObject
    {
        if ($fileId === null) {
            return null;
        }

        $file = FileObject::query()->whereKey($fileId)->where('owner_user_id', $actor->id)->where('classification', $classification)->lockForUpdate()->first();
        if ($file === null || $this->isAttached($file)) {
            throw ValidationException::withMessages([$field => 'Berkas tidak ditemukan atau sudah dipakai.']);
        }

        return $file;
    }

    public function isAttached(FileObject $file): bool
    {
        return Payment::query()->where('proof_file_id', $file->id)->exists()
            || FinanceTransaction::query()->where('receipt_file_id', $file->id)->exists()
            || UnitLetterSetting::query()->where('signature_file_id', $file->id)->orWhere('logo_file_id', $file->id)->exists()
            || DocumentRequest::query()->where('output_file_id', $file->id)->exists();
    }

    public function delete(FileObject $file): void
    {
        foreach ($this->disks() as $disk) {
            $disk->delete($file->storage_key);
        }
        $file->forceDelete();
    }

    /** @return list<Filesystem> disk utama, lalu disk cadangan bila diatur. */
    private function disks(): array
    {
        return array_map(
            fn (string $name) => Storage::disk($name),
            array_values(array_unique(array_filter([config('akur.files.disk'), config('akur.files.fallback_disk')]))),
        );
    }
}
