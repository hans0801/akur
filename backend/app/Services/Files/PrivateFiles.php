<?php

namespace App\Services\Files;

use App\Models\FileObject;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Penyimpanan berkas privat (W8/W9). Isi dienkripsi dengan APP_KEY sebelum ditulis ke disk sehingga salinan
 * disk/volume VPS tidak membuka bukti bayar atau nota; checksum SHA-256 isi asli diverifikasi saat dibaca.
 * Berkas diunggah dulu, lalu "diklaim" oleh pembayaran/transaksi kas milik pengunggah yang sama.
 */
class PrivateFiles
{
    private const DISK = 'local';

    private const KEY_VERSION = 'app-key';

    public function store(UploadedFile $upload, User $owner, string $classification): FileObject
    {
        $content = $upload->get();
        if ($content === false || $content === '') {
            throw ValidationException::withMessages(['file' => 'Berkas kosong atau tidak terbaca.']);
        }

        $key = $classification.'/'.now()->format('Y/m').'/'.Str::uuid();
        Storage::disk(self::DISK)->put($key, Crypt::encryptString($content));

        $file = new FileObject([
            'owner_user_id' => $owner->id,
            'storage_key' => $key,
            'original_filename' => Str::limit(basename($upload->getClientOriginalName()), 200, ''),
            // Tipe dari isi berkas (finfo), sudah divalidasi aturan `mimetypes` di controller.
            'mime_type' => $upload->getMimeType(),
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
        $stored = Storage::disk(self::DISK)->get($file->storage_key);
        if ($stored === null) {
            throw new RuntimeException('Berkas tidak ditemukan di penyimpanan.');
        }
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
            || FinanceTransaction::query()->where('receipt_file_id', $file->id)->exists();
    }

    public function delete(FileObject $file): void
    {
        Storage::disk(self::DISK)->delete($file->storage_key);
        $file->forceDelete();
    }
}
