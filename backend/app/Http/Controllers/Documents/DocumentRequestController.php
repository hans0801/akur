<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeUnit;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Resident;
use App\Models\User;
use App\Models\UserMembership;
use App\Services\Access\UnitAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\LetterNumberer;
use App\Services\Notifications\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * W7: surat pengantar. Warga mengajukan untuk dirinya atau anggota keluarganya; pengurus RT meninjau,
 * meminta perbaikan, menyetujui/menolak, lalu menerbitkan dengan nomor surat.
 * Isian formulir bisa berisi data pribadi: tidak pernah disalin ke audit log maupun notifikasi.
 */
class DocumentRequestController extends Controller
{
    public function __construct(
        private readonly UnitAccess $access,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function types(): JsonResponse
    {
        return response()->json(['data' => DocumentType::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'description', 'required_fields'])]);
    }

    /** Data warga yang boleh diajukan suratnya oleh pengguna: dirinya dan anggota aktif keluarganya. */
    public function eligibleResidents(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->eligibleResidentQuery($request->user())
            ->orderByDesc('is_household_head')->orderBy('full_name')
            ->get(['id', 'full_name', 'is_household_head'])]);
    }

    public function mine(Request $request): JsonResponse
    {
        $page = DocumentRequest::query()
            ->where('requester_user_id', $request->user()->id)
            ->with(['documentType:id,code,name', 'resident:id,full_name', 'unit:id,name'])
            ->latest('submitted_at')
            ->paginate(20);
        $page->getCollection()->transform(fn (DocumentRequest $d) => $this->present($request, $d));

        return response()->json($page);
    }

    /** Antrean pengurus untuk unit (RW: termasuk RT di bawahnya). */
    public function queue(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->access->authorize($request->user(), $unit, config('akur.document_read_roles'));
        $status = $request->query('status');

        $page = DocumentRequest::query()
            ->whereIn('unit_id', $unit->selfAndDescendantIds())
            ->when(
                $status === 'open' || $status === null,
                fn ($q) => $q->whereIn('status', [DocumentRequest::SUBMITTED, DocumentRequest::UNDER_REVIEW, DocumentRequest::APPROVED]),
                fn ($q) => $q->when($status !== 'all', fn ($q) => $q->where('status', $status)),
            )
            ->with(['documentType:id,code,name', 'resident:id,full_name', 'unit:id,name'])
            ->orderBy('submitted_at')
            ->paginate(20);
        $page->getCollection()->transform(fn (DocumentRequest $d) => $this->present($request, $d));

        return response()->json($page);
    }

    public function show(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->authorizeView($request, $documentRequest);

        return response()->json(['data' => $this->present($request, $documentRequest->load(['documentType', 'resident:id,full_name', 'unit:id,name', 'processor:id,display_name']), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $base = $request->validate([
            'document_type_id' => ['required', 'uuid'],
            'resident_id' => ['required', 'uuid'],
        ]);

        $type = DocumentType::query()->whereKey($base['document_type_id'])->where('is_active', true)->first();
        if ($type === null) {
            throw ValidationException::withMessages(['document_type_id' => 'Jenis surat tidak tersedia.']);
        }
        $resident = $this->eligibleResidentQuery($user)->whereKey($base['resident_id'])->with('household')->first();
        if ($resident === null) {
            throw ValidationException::withMessages(['resident_id' => 'Anda hanya dapat mengajukan surat untuk diri sendiri atau anggota keluarga Anda.']);
        }
        $data = $request->validate($this->formRules($type));

        $documentRequest = DB::transaction(function () use ($user, $type, $resident, $data) {
            $documentRequest = DocumentRequest::query()->create([
                'unit_id' => $resident->household->unit_id,
                'requester_user_id' => $user->id,
                'resident_id' => $resident->id,
                'document_type_id' => $type->id,
                'purpose' => $data['purpose'],
                'form_data' => $data['form_data'] ?? [],
                'status' => DocumentRequest::SUBMITTED,
                'submitted_at' => now(),
            ]);
            $this->audit->log('document_request.submitted', 'document_request', $documentRequest->id, $documentRequest->unit_id, [
                'document_type' => $type->code,
                'resident_id' => $resident->id,
            ]);
            $this->notifyProcessors($documentRequest, $user, 'document_request.submitted', 'Pengajuan surat baru', $type->name);

            return $documentRequest;
        });

        return response()->json(['data' => $this->present($request, $documentRequest->load(['documentType', 'resident:id,full_name', 'unit:id,name']), true)], 201);
    }

    /** Pemohon memperbaiki pengajuan yang diminta revisi; status kembali ke `submitted`. */
    public function resubmit(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->ensureRequester($request, $documentRequest);
        abort_if($documentRequest->status !== DocumentRequest::REVISION_REQUESTED, 409, 'Pengajuan ini tidak sedang menunggu perbaikan.');
        $data = $request->validate($this->formRules($documentRequest->documentType));

        DB::transaction(function () use ($request, $documentRequest, $data) {
            $documentRequest->update([
                'purpose' => $data['purpose'],
                'form_data' => $data['form_data'] ?? [],
                'status' => DocumentRequest::SUBMITTED,
                'submitted_at' => now(),
            ]);
            $this->audit->log('document_request.resubmitted', 'document_request', $documentRequest->id, $documentRequest->unit_id);
            $this->notifyProcessors($documentRequest, $request->user(), 'document_request.resubmitted', 'Pengajuan surat diperbaiki', $documentRequest->documentType->name);
        });

        return $this->show($request, $documentRequest->refresh());
    }

    public function cancel(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->ensureRequester($request, $documentRequest);
        abort_if(! in_array($documentRequest->status, DocumentRequest::CANCELLABLE, true), 409, 'Pengajuan ini tidak dapat dibatalkan lagi.');

        DB::transaction(function () use ($documentRequest) {
            $documentRequest->update(['status' => DocumentRequest::CANCELLED]);
            $this->audit->log('document_request.cancelled', 'document_request', $documentRequest->id, $documentRequest->unit_id);
        });

        return $this->show($request, $documentRequest->refresh());
    }

    /** Tinjauan pengurus: under_review / revision_requested / approved / rejected / issued. */
    public function transition(Request $request, DocumentRequest $documentRequest, LetterNumberer $numberer): JsonResponse
    {
        $actor = $request->user();
        $this->access->authorize($actor, $documentRequest->unit, config('akur.document_process_roles'));
        $data = $request->validate([
            'status' => ['required', Rule::in([DocumentRequest::UNDER_REVIEW, DocumentRequest::REVISION_REQUESTED, DocumentRequest::APPROVED, DocumentRequest::REJECTED, DocumentRequest::ISSUED])],
            'notes' => [Rule::requiredIf(in_array($request->input('status'), [DocumentRequest::REVISION_REQUESTED, DocumentRequest::REJECTED], true)), 'nullable', 'string', 'max:1000'],
        ]);
        // Pemohon tidak memproses suratnya sendiri (pemisahan tugas).
        if ($documentRequest->requester_user_id === $actor->id) {
            throw new AuthorizationException('Pengajuan Anda sendiri harus diproses pengurus lain.');
        }

        DB::transaction(function () use ($documentRequest, $data, $actor, $numberer) {
            $locked = DocumentRequest::query()->lockForUpdate()->findOrFail($documentRequest->id);
            $from = $locked->status;
            abort_if(! in_array($data['status'], DocumentRequest::REVIEW_TRANSITIONS[$from] ?? [], true), 409, "Status tidak dapat diubah dari {$from} ke {$data['status']}.");

            $locked->fill([
                'status' => $data['status'],
                'admin_notes' => $data['notes'] ?? $locked->admin_notes,
                'processed_by' => $actor->id,
                'processed_at' => now(),
            ]);
            if ($data['status'] === DocumentRequest::ISSUED) {
                $locked->fill(['letter_number' => $numberer->next($locked->unit), 'issued_at' => now()]);
            }
            $locked->save();

            $this->audit->log('document_request.status_changed', 'document_request', $locked->id, $locked->unit_id, [
                'from' => $from,
                'to' => $locked->status,
                'letter_number' => $locked->letter_number,
            ]);

            $messages = [
                DocumentRequest::UNDER_REVIEW => 'sedang ditinjau',
                DocumentRequest::REVISION_REQUESTED => 'perlu diperbaiki',
                DocumentRequest::APPROVED => 'disetujui',
                DocumentRequest::REJECTED => 'ditolak',
                DocumentRequest::ISSUED => 'sudah terbit dan bisa dicetak',
            ];
            $this->notifier->send($locked->requester_user_id, 'document_request.'.$locked->status, 'Status surat', "{$locked->documentType->name} {$messages[$locked->status]}.", [
                'document_request_id' => $locked->id,
            ]);
        });

        return $this->show($request, $documentRequest->refresh());
    }

    /**
     * Tautan bertanda tangan berumur pendek ke surat siap cetak (HTML). Surat memuat NIK, jadi pembuatan tautan diaudit.
     * Tautan dipakai karena browser/aplikasi eksternal tidak membawa token Bearer.
     */
    public function letterLink(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->authorizeView($request, $documentRequest);
        abort_if($documentRequest->status !== DocumentRequest::ISSUED, 409, 'Surat belum terbit.');

        $expires = now()->addMinutes(config('akur.letter_link_ttl_minutes'));
        $url = URL::temporarySignedRoute('documents.letter', $expires, ['documentRequest' => $documentRequest->id]);
        $this->audit->log('document_request.letter_link_created', 'document_request', $documentRequest->id, $documentRequest->unit_id);

        return response()->json(['data' => ['url' => $url, 'expires_at' => $expires->toIso8601String()]])->header('Cache-Control', 'no-store');
    }

    private function eligibleResidentQuery(User $user)
    {
        $own = $user->resident()->with('household')->first();
        abort_if($own === null || $own->household === null, 403, 'Akun Anda belum terhubung ke data warga.');

        return Resident::query()->where(fn ($q) => $q->whereKey($own->id)->orWhere(fn ($m) => $m
            ->where('household_id', $own->household_id)
            ->where('residency_status', '!=', Resident::STATUS_MOVED_OUT)));
    }

    /** @return array<string, list<mixed>> */
    private function formRules(DocumentType $type): array
    {
        $rules = [
            'purpose' => ['required', 'string', 'max:500'],
            'form_data' => ['array'],
        ];
        foreach ($type->fields() as $field) {
            $rules["form_data.{$field['key']}"] = [
                $field['required'] ? 'required' : 'nullable',
                ...match ($field['type']) {
                    'date' => ['date'],
                    'number' => ['numeric', 'min:0'],
                    'textarea' => ['string', 'max:1000'],
                    default => ['string', 'max:200'],
                },
            ];
        }

        return $rules;
    }

    private function authorizeView(Request $request, DocumentRequest $documentRequest): void
    {
        if ($documentRequest->requester_user_id !== $request->user()->id) {
            $this->access->authorize($request->user(), $documentRequest->unit, config('akur.document_read_roles'));
        }
    }

    private function ensureRequester(Request $request, DocumentRequest $documentRequest): void
    {
        if ($documentRequest->requester_user_id !== $request->user()->id) {
            throw new AuthorizationException('Hanya pemohon yang dapat mengubah pengajuan ini.');
        }
    }

    private function notifyProcessors(DocumentRequest $documentRequest, User $actor, string $type, string $title, string $body): void
    {
        $unit = $documentRequest->unit;
        $userIds = UserMembership::query()
            ->effective()
            ->whereIn('unit_id', $unit->selfAndAncestorIds())
            ->whereHas('role', fn ($q) => $q->whereIn('code', config('akur.document_process_roles')))
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            if ($userId !== $actor->id) {
                $this->notifier->send($userId, $type, $title, "{$body} · {$unit->name}", ['document_request_id' => $documentRequest->id]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function present(Request $request, DocumentRequest $d, bool $detail = false): array
    {
        $data = [
            'id' => $d->id,
            'status' => $d->status,
            'document_type' => ['id' => $d->documentType->id, 'code' => $d->documentType->code, 'name' => $d->documentType->name],
            'resident_name' => $d->resident?->full_name,
            'unit' => ['id' => $d->unit_id, 'name' => $d->unit?->name],
            'purpose' => $d->purpose,
            'letter_number' => $d->letter_number,
            'submitted_at' => $d->submitted_at?->toIso8601String(),
            'issued_at' => $d->issued_at?->toIso8601String(),
            'is_mine' => $d->requester_user_id === $request->user()->id,
        ];

        if ($detail) {
            $data += [
                'resident_id' => $d->resident_id,
                'form_data' => $d->form_data ?? [],
                'fields' => $d->documentType->fields(),
                'admin_notes' => $d->admin_notes,
                'processed_by' => $d->processor?->display_name,
                'processed_at' => $d->processed_at?->toIso8601String(),
                'can_process' => $d->requester_user_id !== $request->user()->id
                    && $this->access->hasAnyRole($request->user(), $d->unit, config('akur.document_process_roles')),
                'next_statuses' => DocumentRequest::REVIEW_TRANSITIONS[$d->status] ?? [],
            ];
        }

        return $data;
    }
}
