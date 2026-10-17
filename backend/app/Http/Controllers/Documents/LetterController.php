<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\LetterPdf;
use Illuminate\Http\Response;

/**
 * PDF surat pengantar lewat tautan bertanda tangan berumur pendek. Memuat NIK utuh, jadi setiap unduhan diaudit
 * dan respons tidak boleh di-cache atau diindeks. PDF dibekukan saat terbit (lihat LetterPdf).
 */
class LetterController extends Controller
{
    public function __invoke(DocumentRequest $documentRequest, LetterPdf $pdf, AuditLogger $audit): Response
    {
        abort_if($documentRequest->status !== DocumentRequest::ISSUED, 404);

        $content = $pdf->contentFor($documentRequest);
        $audit->log('document_request.letter_viewed', 'document_request', $documentRequest->id, $documentRequest->unit_id, actorUserId: $documentRequest->requester_user_id);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'attachment; filename="'.LetterPdf::filename($documentRequest).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
