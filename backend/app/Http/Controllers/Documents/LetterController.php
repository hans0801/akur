<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentRequest;
use App\Models\Role;
use App\Models\UserMembership;
use App\Services\Audit\AuditLogger;
use App\Services\Pii\PopulationPii;
use Illuminate\Http\Response;

/**
 * Surat siap cetak (HTML A4) lewat tautan bertanda tangan berumur pendek. Memuat NIK utuh,
 * jadi setiap pembukaan diaudit dan respons tidak boleh di-cache atau diindeks.
 */
class LetterController extends Controller
{
    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __invoke(DocumentRequest $documentRequest, PopulationPii $pii, AuditLogger $audit): Response
    {
        abort_if($documentRequest->status !== DocumentRequest::ISSUED, 404);
        $documentRequest->load(['documentType', 'unit.parent', 'resident.household']);
        $resident = $documentRequest->resident;
        $unit = $documentRequest->unit;

        $signer = UserMembership::query()
            ->effective()
            ->where('unit_id', $unit->id)
            ->whereHas('role', fn ($q) => $q->where('code', Role::KETUA_RT))
            ->with('user.resident')
            ->first()?->user;

        $audit->log('document_request.letter_viewed', 'document_request', $documentRequest->id, $unit->id, actorUserId: $documentRequest->requester_user_id);

        $html = view('letters.surat', [
            'request' => $documentRequest,
            'resident' => $resident,
            'nik' => $pii->reveal($resident->getAttributes()['nik_ciphertext'] ?? null, PopulationPii::NIK),
            'unit' => $unit,
            'fields' => $documentRequest->documentType->fields(),
            'signerName' => $signer?->resident?->full_name ?? $signer?->display_name,
            'formatDate' => fn ($date) => $date ? $date->day.' '.self::MONTHS[$date->month - 1].' '.$date->year : '-',
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'",
        ]);
    }
}
