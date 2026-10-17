<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeUnit;
use App\Models\FileObject;
use App\Models\Role;
use App\Models\UnitLetterSetting;
use App\Models\User;
use App\Services\Access\UnitAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\LetterPdf;
use App\Services\Files\PrivateFiles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Pengaturan surat per unit: nama penanda tangan (Ketua RT/RW), wilayah untuk kop, logo kop,
 * serta gambar tanda tangan + cap. Tanda tangan bersifat pribadi: pengaturan RT diubah Ketua RT,
 * pengaturan RW diubah Ketua RW (super_admin keduanya).
 */
class LetterSettingsController extends Controller
{
    private const FIELDS = ['signer_name', 'kelurahan', 'kecamatan', 'city', 'province'];

    public function __construct(
        private readonly UnitAccess $access,
        private readonly AuditLogger $audit,
        private readonly PrivateFiles $files,
    ) {}

    public function show(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->access->authorize($request->user(), $unit, config('akur.document_read_roles'));

        return response()->json(['data' => $this->present($request->user(), $unit)]);
    }

    public function update(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeEdit($request->user(), $unit);
        $data = $request->validate([
            'signer_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'kelurahan' => ['sometimes', 'nullable', 'string', 'max:120'],
            'kecamatan' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'province' => ['sometimes', 'nullable', 'string', 'max:120'],
            'signature_file_id' => ['sometimes', 'nullable', 'uuid'],
            'logo_file_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        DB::transaction(function () use ($request, $unit, $data) {
            $settings = UnitLetterSetting::query()->lockForUpdate()->find($unit->id) ?? new UnitLetterSetting(['unit_id' => $unit->id]);
            $settings->fill(collect($data)->only(self::FIELDS)->all());

            foreach (['signature_file_id' => FileObject::LETTER_SIGNATURE, 'logo_file_id' => FileObject::LETTER_LOGO] as $column => $classification) {
                if (! array_key_exists($column, $data) || $data[$column] === $settings->{$column}) {
                    continue;
                }
                $old = $settings->{$column} ? FileObject::query()->find($settings->{$column}) : null;
                $settings->{$column} = $this->files->claim($data[$column], $request->user(), $classification, $column)?->id;
                // Gambar lama tidak dipakai lagi; PDF surat yang sudah terbit tetap menyimpan salinannya sendiri.
                if ($old !== null) {
                    DB::afterCommit(fn () => $this->files->delete($old));
                }
            }

            $changed = array_keys($settings->getDirty());
            $settings->updated_by = $request->user()->id;
            $settings->save();

            if ($changed !== []) {
                $this->audit->log('letter_settings.updated', 'administrative_unit', $unit->id, $unit->id, ['fields' => $changed]);
            }
        });

        return response()->json(['data' => $this->present($request->user(), $unit)]);
    }

    /** Tautan bertanda tangan ke contoh PDF (data fiktif) untuk memeriksa kop dan tanda tangan. */
    public function previewLink(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->access->authorize($request->user(), $unit, config('akur.document_read_roles'));
        $expires = now()->addMinutes(config('akur.letter_link_ttl_minutes'));

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('documents.letter-preview', $expires, ['unit' => $unit->id]),
            'expires_at' => $expires->toIso8601String(),
        ]])->header('Cache-Control', 'no-store');
    }

    public function preview(AdministrativeUnit $unit, LetterPdf $pdf): Response
    {
        $content = $pdf->sample($unit);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Contoh-Surat-Pengantar.pdf"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** Peran yang boleh mengubah pengaturan unit ini. */
    public static function editRoles(AdministrativeUnit $unit): array
    {
        return $unit->unit_type === AdministrativeUnit::TYPE_RT ? [Role::SUPER_ADMIN, Role::KETUA_RT] : [Role::SUPER_ADMIN, Role::KETUA_RW];
    }

    private function authorizeEdit(User $user, AdministrativeUnit $unit): void
    {
        if (! $this->access->hasAnyRole($user, $unit, self::editRoles($unit))) {
            throw new AuthorizationException($unit->unit_type === AdministrativeUnit::TYPE_RT
                ? 'Hanya Ketua RT yang dapat mengubah pengaturan surat RT ini.'
                : 'Hanya Ketua RW yang dapat mengubah pengaturan surat RW ini.');
        }
    }

    /** @return array<string, mixed> */
    private function present(User $user, AdministrativeUnit $unit): array
    {
        $settings = UnitLetterSetting::query()->find($unit->id);

        return [
            'unit' => ['id' => $unit->id, 'name' => $unit->name, 'unit_type' => $unit->unit_type, 'code' => $unit->code],
            ...collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f => $settings?->{$f}])->all(),
            'signature_file_id' => $settings?->signature_file_id,
            'logo_file_id' => $settings?->logo_file_id,
            'can_edit' => $this->access->hasAnyRole($user, $unit, self::editRoles($unit)),
            'updated_at' => $settings?->updated_at?->toIso8601String(),
        ];
    }
}
