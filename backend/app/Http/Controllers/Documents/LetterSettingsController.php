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
 * Pengaturan surat dalam satu tampilan per RT: kop (wilayah + logo), Ketua RT (nama + tanda tangan/cap),
 * dan Ketua RW induk (nama + tanda tangan/cap). Data tetap disimpan per unit di unit_letter_settings:
 * kop dan Ketua RT di baris RT, Ketua RW di baris RW. Tanda tangan bersifat pribadi: bagian RT diubah
 * Ketua RT, bagian RW diubah Ketua RW, kop boleh diubah keduanya (super_admin semuanya).
 */
class LetterSettingsController extends Controller
{
    private const KOP_FIELDS = ['kelurahan', 'kecamatan', 'city', 'province'];

    public function __construct(
        private readonly UnitAccess $access,
        private readonly AuditLogger $audit,
        private readonly PrivateFiles $files,
    ) {}

    /**
     * Unit yang pengaturan suratnya boleh dibuka pengguna: RT keanggotaannya beserta RT di bawah RW-nya.
     * RW hanya tampil sendiri bila belum punya RT (surat selalu terbit atas nama RT; Ketua RW ada di tiap RT).
     */
    public function units(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = $this->access->unitIdsWhere($user, config('akur.document_read_roles'));

        $units = AdministrativeUnit::query()
            ->whereIn('id', $ids)
            ->with('parent:id,name,code')
            ->withCount('children')
            ->get()
            ->filter(fn (AdministrativeUnit $u) => $u->unit_type === AdministrativeUnit::TYPE_RT || $u->children_count === 0)
            ->sortBy(fn (AdministrativeUnit $u) => [$u->parent?->code ?? $u->code, $u->code])
            ->values()
            ->map(fn (AdministrativeUnit $u) => [
                'id' => $u->id,
                'unit_type' => $u->unit_type,
                'code' => $u->code,
                'name' => $u->name,
                'parent_unit_id' => $u->parent_unit_id,
                'label' => $u->label(),
                'can_edit' => $this->canEditAny($user, $u),
            ]);

        return response()->json(['data' => $units]);
    }

    public function show(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->access->authorize($request->user(), $unit, config('akur.document_read_roles'));

        return response()->json(['data' => $this->present($request->user(), $unit)]);
    }

    /**
     * Simpan kop (field datar), bagian `rt` dan bagian `rw` sekaligus. Hanya bagian yang dikirim yang diperiksa haknya.
     */
    public function update(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $user = $request->user();
        $this->access->authorize($user, $unit, config('akur.document_read_roles'));
        $signer = fn (string $key) => [
            $key => ['sometimes', 'array:signer_name,signature_file_id'],
            "{$key}.signer_name" => ['sometimes', 'nullable', 'string', 'max:160'],
            "{$key}.signature_file_id" => ['sometimes', 'nullable', 'uuid'],
        ];
        $data = $request->validate([
            'kelurahan' => ['sometimes', 'nullable', 'string', 'max:120'],
            'kecamatan' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'province' => ['sometimes', 'nullable', 'string', 'max:120'],
            'logo_file_id' => ['sometimes', 'nullable', 'uuid'],
            ...$signer('rt'),
            ...$signer('rw'),
        ]);

        [$rt, $rw] = self::signerUnits($unit);
        $kop = collect($data)->only([...self::KOP_FIELDS, 'logo_file_id'])->all();
        $parts = [];
        if ($kop !== []) {
            if (! $this->canEditKop($user, $unit)) {
                throw new AuthorizationException('Hanya Ketua RT atau Ketua RW yang dapat mengubah kop surat.');
            }
            $parts[] = [$unit, $kop];
        }
        foreach (['rt' => $rt, 'rw' => $rw] as $key => $signerUnit) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if ($signerUnit === null || ! $this->canEditOwn($user, $signerUnit)) {
                throw new AuthorizationException($key === 'rt'
                    ? 'Hanya Ketua RT yang dapat mengubah nama dan tanda tangan Ketua RT.'
                    : 'Hanya Ketua RW yang dapat mengubah nama dan tanda tangan Ketua RW.');
            }
            $parts[] = [$signerUnit, $data[$key]];
        }
        if ($parts === [] && ! $this->canEditAny($user, $unit)) {
            throw new AuthorizationException('Anda tidak dapat mengubah pengaturan surat ini.');
        }

        DB::transaction(function () use ($user, $parts) {
            foreach ($parts as [$target, $values]) {
                $this->apply($user, $target, $values);
            }
        });

        return response()->json(['data' => $this->present($user, $unit)]);
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

    /** Peran yang boleh mengubah nama/tanda tangan ketua unit ini. */
    public static function editRoles(AdministrativeUnit $unit): array
    {
        return $unit->unit_type === AdministrativeUnit::TYPE_RT ? [Role::SUPER_ADMIN, Role::KETUA_RT] : [Role::SUPER_ADMIN, Role::KETUA_RW];
    }

    /**
     * Unit penanda tangan untuk surat unit ini: [RT, RW induk]; RW tanpa RT hanya [null, RW].
     *
     * @return array{0: ?AdministrativeUnit, 1: ?AdministrativeUnit}
     */
    private static function signerUnits(AdministrativeUnit $unit): array
    {
        return $unit->unit_type === AdministrativeUnit::TYPE_RT ? [$unit, $unit->parent] : [null, $unit];
    }

    private function canEditOwn(User $user, AdministrativeUnit $unit): bool
    {
        return $this->access->hasAnyRole($user, $unit, self::editRoles($unit));
    }

    /** Kop disimpan di unit itu sendiri; Ketua RT maupun Ketua RW induk boleh mengubahnya. */
    private function canEditKop(User $user, AdministrativeUnit $unit): bool
    {
        return array_filter(self::signerUnits($unit), fn (?AdministrativeUnit $u) => $u !== null && $this->canEditOwn($user, $u)) !== [];
    }

    private function canEditAny(User $user, AdministrativeUnit $unit): bool
    {
        return $this->canEditKop($user, $unit);
    }

    /** @param  array<string, mixed>  $values */
    private function apply(User $user, AdministrativeUnit $unit, array $values): void
    {
        $settings = UnitLetterSetting::query()->lockForUpdate()->find($unit->id) ?? new UnitLetterSetting(['unit_id' => $unit->id]);
        $settings->fill(collect($values)->only(['signer_name', ...self::KOP_FIELDS])->all());

        foreach (['signature_file_id' => FileObject::LETTER_SIGNATURE, 'logo_file_id' => FileObject::LETTER_LOGO] as $column => $classification) {
            if (! array_key_exists($column, $values) || $values[$column] === $settings->{$column}) {
                continue;
            }
            $old = $settings->{$column} ? FileObject::query()->find($settings->{$column}) : null;
            $settings->{$column} = $this->files->claim($values[$column], $user, $classification, $column)?->id;
            // Gambar lama tidak dipakai lagi; PDF surat yang sudah terbit tetap menyimpan salinannya sendiri.
            if ($old !== null) {
                DB::afterCommit(fn () => $this->files->delete($old));
            }
        }

        $changed = array_keys($settings->getDirty());
        $settings->updated_by = $user->id;
        $settings->save();

        if ($changed !== []) {
            $this->audit->log('letter_settings.updated', 'administrative_unit', $unit->id, $unit->id, ['fields' => $changed]);
        }
    }

    /**
     * Satu tampilan: kop efektif (isian unit, atau RW induk bila kosong), lalu Ketua RT dan Ketua RW.
     *
     * @return array<string, mixed>
     */
    private function present(User $user, AdministrativeUnit $unit): array
    {
        [$rt, $rw] = self::signerUnits($unit);
        $own = UnitLetterSetting::query()->find($unit->id);
        $parent = $rt !== null && $rw !== null ? UnitLetterSetting::query()->find($rw->id) : null;
        $rwSettings = $rt === null ? $own : $parent;

        $signer = fn (?AdministrativeUnit $u, ?UnitLetterSetting $s) => $u === null ? null : [
            'unit' => ['id' => $u->id, 'name' => $u->label(), 'unit_type' => $u->unit_type, 'code' => $u->code],
            'signer_name' => $s?->signer_name,
            'signature_file_id' => $s?->signature_file_id,
            'can_edit' => $this->canEditOwn($user, $u),
        ];

        return [
            'unit' => ['id' => $unit->id, 'name' => $unit->label(), 'unit_type' => $unit->unit_type, 'code' => $unit->code],
            'kop' => [
                ...collect(self::KOP_FIELDS)->mapWithKeys(fn ($f) => [$f => $own?->{$f} ?: $parent?->{$f}])->all(),
                'logo_file_id' => $own?->logo_file_id ?? $parent?->logo_file_id,
                'can_edit' => $this->canEditKop($user, $unit),
            ],
            'rt' => $signer($rt, $own),
            'rw' => $signer($rw, $rwSettings),
            'can_edit' => $this->canEditAny($user, $unit),
            'updated_at' => collect([$own?->updated_at, $parent?->updated_at])->filter()->max()?->toIso8601String(),
        ];
    }
}
