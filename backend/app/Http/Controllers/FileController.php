<?php

namespace App\Http\Controllers;

use App\Models\FileObject;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\Role;
use App\Models\UnitLetterSetting;
use App\Models\User;
use App\Services\Access\UnitAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Files\PrivateFiles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * W8/W9: unggah & lihat berkas privat. Akses mengikuti entitas tempat berkas dilampirkan:
 * bukti bayar → pembayar/keluarganya atau pengurus keuangan unit; nota kas → pengurus keuangan unit;
 * belum dilampirkan → hanya pengunggah. Berkas dibuka lewat tautan bertanda tangan 5 menit (diaudit).
 */
class FileController extends Controller
{
    public function __construct(
        private readonly PrivateFiles $files,
        private readonly UnitAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $imageOnly = in_array($request->input('purpose'), FileObject::IMAGE_ONLY, true);
        $data = $request->validate([
            'purpose' => ['required', Rule::in(FileObject::CLASSIFICATIONS)],
            'file' => $imageOnly
                // Tanda tangan/logo masuk ke PDF: hanya PNG/JPEG (PNG transparan disarankan), maks 2 MB.
                ? ['required', 'file', 'max:2048', 'mimetypes:image/png,image/jpeg']
                : ['required', 'file', 'max:'.config('akur.uploads.max_kb'), 'mimetypes:'.implode(',', config('akur.uploads.mimes'))],
        ]);
        if ($data['purpose'] === FileObject::FINANCE_RECEIPT
            && $this->access->unitIdsWhere($request->user(), config('akur.finance_write_roles')) === []) {
            throw new AuthorizationException('Hanya bendahara yang dapat mengunggah nota kas.');
        }
        if ($imageOnly && $this->access->unitIdsWhere($request->user(), [Role::SUPER_ADMIN, Role::KETUA_RT, Role::KETUA_RW]) === []) {
            throw new AuthorizationException('Hanya Ketua RT/RW yang dapat mengunggah tanda tangan atau logo surat.');
        }

        $file = $this->files->store($request->file('file'), $request->user(), $data['purpose']);
        $this->audit->log('file.uploaded', 'file_object', $file->id, metadata: [
            'classification' => $file->classification,
            'mime_type' => $file->mime_type,
            'byte_size' => $file->byte_size,
        ]);

        return response()->json(['data' => $file->only(['id', 'original_filename', 'mime_type', 'byte_size', 'classification'])], 201);
    }

    public function link(Request $request, FileObject $file): JsonResponse
    {
        $this->authorizeView($request->user(), $file);

        $expires = now()->addMinutes(config('akur.letter_link_ttl_minutes'));
        $url = URL::temporarySignedRoute('files.download', $expires, ['file' => $file->id]);
        $this->audit->log('file.link_created', 'file_object', $file->id);

        return response()->json(['data' => ['url' => $url, 'mime_type' => $file->mime_type, 'expires_at' => $expires->toIso8601String()]])
            ->header('Cache-Control', 'no-store');
    }

    public function download(FileObject $file): Response
    {
        $content = $this->files->read($file);
        $this->audit->log('file.viewed', 'file_object', $file->id, actorUserId: $file->owner_user_id);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->original_filename) ?: 'berkas';

        return response($content, 200, [
            'Content-Type' => $file->mime_type,
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            // Gambar/PDF ditampilkan apa adanya; tidak ada skrip yang boleh berjalan dari berkas unggahan.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    private function authorizeView(User $user, FileObject $file): void
    {
        $payment = Payment::query()->where('proof_file_id', $file->id)->with('household.unit')->first();
        if ($payment !== null) {
            $ownHousehold = $user->resident?->household_id;
            if ($payment->payer_user_id === $user->id
                || ($ownHousehold !== null && $ownHousehold === $payment->household_id)
                || $this->access->hasAnyRole($user, $payment->household->unit, config('akur.finance_read_roles'))) {
                return;
            }
            throw new AuthorizationException('Anda tidak memiliki akses ke berkas ini.');
        }

        $transaction = FinanceTransaction::query()->where('receipt_file_id', $file->id)->with('unit')->first();
        if ($transaction !== null) {
            $this->access->authorize($user, $transaction->unit, config('akur.finance_read_roles'));

            return;
        }

        $settings = UnitLetterSetting::query()
            ->where('signature_file_id', $file->id)->orWhere('logo_file_id', $file->id)
            ->with('unit')->first();
        if ($settings !== null) {
            // Pengaturan RW tampil juga di layar pengaturan surat setiap RT di bawahnya.
            $readable = $this->access->unitIdsWhere($user, config('akur.document_read_roles'));
            if (array_intersect($settings->unit->selfAndDescendantIds(), $readable) === []) {
                throw new AuthorizationException('Anda tidak memiliki akses ke berkas ini.');
            }

            return;
        }

        // PDF surat hanya lewat tautan surat (DocumentRequestController::letterLink), bukan lewat endpoint berkas.
        if ($file->classification === FileObject::LETTER_PDF || $file->owner_user_id !== $user->id) {
            throw new AuthorizationException('Anda tidak memiliki akses ke berkas ini.');
        }
    }
}
