<?php

namespace App\Services\Documents;

use App\Models\AdministrativeUnit;
use App\Models\DocumentRequest;
use App\Models\FileObject;
use App\Models\Role;
use App\Models\UnitLetterSetting;
use App\Models\UserMembership;
use App\Services\Files\PrivateFiles;
use App\Services\Pii\PopulationPii;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PDF surat pengantar sesuai template "[Template]Surat Pengantar.docx" (kop RT/RW, data warga, dua penanda tangan).
 * Data kop & penanda tangan diambil dari unit_letter_settings RT, lalu RW induk sebagai cadangan.
 * PDF dibekukan saat surat terbit (document_requests.output_file_id) agar isi surat resmi tidak berubah
 * walau pengaturan/tanda tangan diganti kemudian.
 */
class LetterPdf
{
    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly PopulationPii $pii,
        private readonly PrivateFiles $files,
    ) {}

    /** PDF tersimpan untuk surat terbit; dibuat dan disimpan bila belum ada. */
    public function contentFor(DocumentRequest $request): string
    {
        $file = $request->output_file_id ? FileObject::query()->find($request->output_file_id) : null;
        if ($file !== null) {
            return $this->files->read($file);
        }

        return $this->files->read($this->generateAndStore($request));
    }

    /** Buat PDF dan simpan sebagai berkas privat terenkripsi; dipanggil saat surat terbit. */
    public function generateAndStore(DocumentRequest $request): FileObject
    {
        $pdf = $this->render($this->requestData($request));
        $file = $this->files->storeContent($pdf, 'application/pdf', self::filename($request), FileObject::LETTER_PDF);
        DB::table('document_requests')->where('id', $request->id)->update(['output_file_id' => $file->id]);
        $request->output_file_id = $file->id;

        return $file;
    }

    /** Coba bekukan PDF; kegagalan tidak membatalkan penerbitan (PDF dibuat ulang saat diunduh). */
    public function tryGenerateAndStore(DocumentRequest $request): void
    {
        try {
            $this->generateAndStore($request);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Contoh surat dengan data fiktif, untuk memeriksa kop dan tanda tangan sebelum dipakai. */
    public function sample(AdministrativeUnit $unit): string
    {
        return $this->render($this->sampleData($unit));
    }

    /** HTML contoh surat (dipakai juga oleh tes). */
    public function sampleHtml(AdministrativeUnit $unit): string
    {
        return view('letters.surat_pengantar', $this->sampleData($unit))->render();
    }

    /**
     * Surat selalu terbit atas nama RT, jadi contoh untuk RW memakai RT pertama di bawahnya
     * (kop RT/RW dan dua penanda tangan tampil seperti surat sungguhan).
     *
     * @return array<string, mixed>
     */
    private function sampleData(AdministrativeUnit $unit): array
    {
        if ($unit->unit_type === AdministrativeUnit::TYPE_RW) {
            $unit = $unit->children()->where('unit_type', AdministrativeUnit::TYPE_RT)->orderBy('code')->first() ?? $unit;
        }
        $now = CarbonImmutable::now(config('akur.report_timezone'));

        return $this->unitData($unit) + [
            'sample' => true,
            'number' => '001/'.LetterNumberer::unitCode($unit).'/'.LetterNumberer::KIND.'/'.$now->format('m/Y'),
            'date' => self::formatDate($now),
            'rows' => [
                ['Nama', 'Budi Contoh'],
                ['N.I.K', '3671010101900001'],
                ['Tempat/tanggal Lahir', 'Tangerang, 1 Januari 1990'],
                ['Pekerjaan', 'Karyawan Swasta'],
                ['Agama', 'Islam'],
                ['Status Pernikahan', 'Kawin'],
                ['Alamat', 'Jl. Contoh No. 1'],
                ['Maksud & Keperluan', 'Contoh surat pengantar'],
            ],
        ];
    }

    public static function filename(DocumentRequest $request): string
    {
        return 'Surat-Pengantar-'.str_replace('/', '-', (string) $request->letter_number).'.pdf';
    }

    /** @return array<string, mixed> */
    private function requestData(DocumentRequest $request): array
    {
        $request->loadMissing(['documentType', 'unit.parent', 'resident.household']);
        $resident = $request->resident;
        $issued = CarbonImmutable::parse($request->issued_at ?? now())->setTimezone(config('akur.report_timezone'));

        $birth = trim(implode(', ', array_filter([$resident->birth_place, $resident->birth_date ? self::formatDate($resident->birth_date) : null])));
        $rows = [
            ['Nama', $resident->full_name],
            ['N.I.K', $this->pii->reveal($resident->getAttributes()['nik_ciphertext'] ?? null, PopulationPii::NIK) ?? '-'],
            ['Tempat/tanggal Lahir', $birth !== '' ? $birth : '-'],
            ['Pekerjaan', $resident->occupation ?: '-'],
            ['Agama', $resident->religion ?: '-'],
            ['Status Pernikahan', $resident->marital_status ?: '-'],
            ['Alamat', $resident->household?->address_text ?: '-'],
        ];

        foreach ($request->documentType->fields() as $field) {
            $value = ($request->form_data ?? [])[$field['key']] ?? null;
            if ($value !== null && $value !== '') {
                $rows[] = [$field['label'], $field['type'] === 'date' ? self::formatDate(CarbonImmutable::parse($value)) : (string) $value];
            }
        }
        $purpose = $request->documentType->code === 'pengantar_umum'
            ? $request->purpose
            : $request->documentType->name.' — '.$request->purpose;
        $rows[] = ['Maksud & Keperluan', $purpose];

        return $this->unitData($request->unit) + [
            'sample' => false,
            'number' => $request->letter_number,
            'date' => self::formatDate($issued),
            'rows' => $rows,
        ];
    }

    /**
     * Kop, wilayah, dan penanda tangan RT & RW. Pengaturan RT dipakai lebih dulu, lalu RW induk.
     *
     * @return array<string, mixed>
     */
    private function unitData(AdministrativeUnit $unit): array
    {
        $rt = $unit->unit_type === AdministrativeUnit::TYPE_RT ? $unit : null;
        $rw = $rt ? $unit->parent : $unit;
        $rtSet = $rt ? UnitLetterSetting::query()->find($rt->id) : null;
        $rwSet = $rw ? UnitLetterSetting::query()->find($rw->id) : null;
        $pick = fn (string $field) => $rtSet?->{$field} ?: ($rwSet?->{$field} ?: null);

        // "005/006" untuk RT; RW tanpa RT hanya "006" (bukan "000/006").
        $pad = fn (?AdministrativeUnit $u) => $u ? str_pad((string) $u->code, 3, '0', STR_PAD_LEFT) : null;
        $pair = implode('/', array_filter([$pad($rt), $pad($rw)]));
        $kelurahan = $pick('kelurahan');
        $kecamatan = $pick('kecamatan');
        $city = $pick('city');
        $province = $pick('province');

        return [
            'unitPair' => $pair,
            'rwCode' => str_pad((string) $rw?->code, 3, '0', STR_PAD_LEFT),
            'kopTitle' => ($rt ? 'RUKUN TETANGGA ' : 'RUKUN WARGA ').$pair,
            'kopRegion' => mb_strtoupper(trim(($kelurahan ? "KELURAHAN {$kelurahan} " : '').($kecamatan ? "KECAMATAN {$kecamatan}" : ''))),
            'kopCity' => mb_strtoupper(trim("{$city} {$province}")),
            'regionText' => trim(($kelurahan ? "Kelurahan {$kelurahan} " : '').($kecamatan ? "Kecamatan {$kecamatan}" : '')),
            'city' => $city,
            'logo' => $this->imageUri($rtSet?->logo_file_id ?? $rwSet?->logo_file_id) ?? self::defaultLogo(),
            'rtSigner' => $rtSet?->signer_name ?: ($rt ? $this->officerName($rt, Role::KETUA_RT) : null),
            'rwSigner' => $rwSet?->signer_name ?: ($rw ? $this->officerName($rw, Role::KETUA_RW) : null),
            'rtSignature' => $this->imageUri($rtSet?->signature_file_id),
            'rwSignature' => $this->imageUri($rwSet?->signature_file_id),
        ];
    }

    /** Nama pemegang peran aktif di unit (nama warga bila tertaut, selain itu nama akun). */
    private function officerName(AdministrativeUnit $unit, string $role): ?string
    {
        $user = UserMembership::query()
            ->effective()
            ->where('unit_id', $unit->id)
            ->whereHas('role', fn ($q) => $q->where('code', $role))
            ->with('user.resident')
            ->oldest('granted_at')
            ->first()?->user;

        return $user?->resident?->full_name ?? $user?->display_name;
    }

    private function imageUri(?string $fileId): ?string
    {
        $file = $fileId ? FileObject::query()->find($fileId) : null;
        if ($file === null) {
            return null;
        }

        return 'data:'.$file->mime_type.';base64,'.base64_encode($this->files->read($file));
    }

    private static function defaultLogo(): string
    {
        return 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('letters/logo-default.png')));
    }

    public static function formatDate(CarbonInterface $date): string
    {
        return $date->day.' '.self::MONTHS[$date->month - 1].' '.$date->year;
    }

    /** HTML sumber PDF (dipakai juga oleh tes untuk memeriksa isi surat). */
    public function htmlFor(DocumentRequest $request): string
    {
        return view('letters.surat_pengantar', $this->requestData($request))->render();
    }

    /** @param  array<string, mixed>  $data */
    private function render(array $data): string
    {
        $options = new Options;
        $options->setDefaultFont('Helvetica');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setChroot(resource_path('letters'));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('letters.surat_pengantar', $data)->render(), 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
