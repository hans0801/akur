<?php

namespace Tests\Feature\Documents;

use App\Models\AdministrativeUnit;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Services\Pii\PopulationPii;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DocumentRequestTest extends TestCase
{
    private const NIK = '3201010101014321';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
    }

    public function test_warga_submits_for_self_and_processors_are_notified(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        [$warga, $resident] = $this->linkedWarga($rt);

        $response = $this->postJson('/api/v1/document-requests', [
            'document_type_id' => $this->type('domisili')->id,
            'resident_id' => $resident->id,
            'purpose' => 'Melamar kerja',
            'form_data' => ['lama_tinggal' => '5 tahun'],
        ], $this->bearerFor($warga))->assertCreated()->assertJsonPath('data.status', 'submitted');

        $id = $response->json('data.id');
        $this->assertSame($rt->id, DocumentRequest::find($id)->unit_id);
        $this->assertTrue(AppNotification::query()->where('user_id', $ketua->id)->where('type', 'document_request.submitted')->exists());
        $this->assertFalse(AppNotification::query()->where('user_id', $bendahara->id)->exists());

        $log = AuditLog::query()->where('action', 'document_request.submitted')->sole();
        $this->assertStringNotContainsString('5 tahun', json_encode($log->metadata));
        $this->assertStringNotContainsString('Melamar', json_encode($log->metadata));

        $this->getJson('/api/v1/document-requests', $this->bearerFor($warga))->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_can_only_request_for_self_or_own_household(): void
    {
        ['rt1' => $rt, 'rt2' => $rt2] = $this->unitTree();
        [$warga, $resident] = $this->linkedWarga($rt);
        $child = Resident::query()->create(['household_id' => $resident->household_id, 'full_name' => 'Anak', 'residency_status' => 'permanent']);
        $neighbour = $this->residentIn($rt);
        $otherRt = $this->residentIn($rt2);
        $headers = $this->bearerFor($warga);
        $payload = fn (Resident $r) => ['document_type_id' => $this->type('skck')->id, 'resident_id' => $r->id, 'purpose' => 'SKCK'];

        $this->postJson('/api/v1/document-requests', $payload($child), $headers)->assertCreated();
        $this->postJson('/api/v1/document-requests', $payload($neighbour), $headers)->assertJsonValidationErrors('resident_id');
        $this->postJson('/api/v1/document-requests', $payload($otherRt), $headers)->assertJsonValidationErrors('resident_id');

        $this->getJson('/api/v1/document-requests/eligible-residents', $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_account_without_resident_cannot_request(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::SEKRETARIS));

        $this->getJson('/api/v1/document-requests/eligible-residents', $headers)->assertForbidden();
    }

    public function test_type_specific_fields_are_required(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        [$warga, $resident] = $this->linkedWarga($rt);

        $this->postJson('/api/v1/document-requests', [
            'document_type_id' => $this->type('usaha')->id,
            'resident_id' => $resident->id,
            'purpose' => 'Pinjaman bank',
            'form_data' => ['nama_usaha' => 'Warung Sari'],
        ], $this->bearerFor($warga))->assertJsonValidationErrors(['form_data.jenis_usaha', 'form_data.alamat_usaha']);
    }

    public function test_full_flow_revision_approve_issue_with_sequential_numbers(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        [$warga, $resident] = $this->linkedWarga($rt);
        $wargaHeaders = $this->bearerFor($warga);
        $first = $this->submit($warga, $resident, 'pengantar_umum');

        $this->postJson("/api/v1/document-requests/{$first}/transition", ['status' => 'revision_requested'], $ketua)->assertJsonValidationErrors('notes');
        $this->postJson("/api/v1/document-requests/{$first}/transition", ['status' => 'revision_requested', 'notes' => 'Keperluan kurang jelas'], $ketua)
            ->assertOk()->assertJsonPath('data.admin_notes', 'Keperluan kurang jelas');

        $this->putJson("/api/v1/document-requests/{$first}", ['purpose' => 'Pembuatan KTP baru'], $wargaHeaders)
            ->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->postJson("/api/v1/document-requests/{$first}/transition", ['status' => 'issued'], $ketua)->assertConflict();
        $this->postJson("/api/v1/document-requests/{$first}/transition", ['status' => 'approved'], $ketua)->assertOk();
        $this->postJson("/api/v1/document-requests/{$first}/transition", ['status' => 'issued'], $ketua)->assertOk();

        $second = $this->submit($warga, $resident, 'skck');
        $this->postJson("/api/v1/document-requests/{$second}/transition", ['status' => 'approved'], $ketua)->assertOk();
        $this->postJson("/api/v1/document-requests/{$second}/transition", ['status' => 'issued'], $ketua)->assertOk();

        $suffix = '/RT05/RW06/'.['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][now()->month - 1].'/'.now()->year;
        $this->assertSame('001'.$suffix, DocumentRequest::find($first)->letter_number);
        $this->assertSame('002'.$suffix, DocumentRequest::find($second)->letter_number);
        $this->assertTrue(AppNotification::query()->where('user_id', $warga->id)->where('type', 'document_request.issued')->exists());

        $this->postJson("/api/v1/document-requests/{$first}/cancel", [], $wargaHeaders)->assertConflict();
    }

    public function test_cross_rt_and_role_restrictions(): void
    {
        ['rw' => $rw, 'rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        [$warga, $resident] = $this->linkedWarga($rt1);
        $id = $this->submit($warga, $resident, 'skck');

        $ketuaRt2 = $this->bearerFor($this->userWithRole($rt2, Role::KETUA_RT));
        $this->getJson("/api/v1/document-requests/{$id}", $ketuaRt2)->assertForbidden();
        $this->postJson("/api/v1/document-requests/{$id}/transition", ['status' => 'approved'], $ketuaRt2)->assertForbidden();
        $this->getJson("/api/v1/units/{$rt1->id}/document-requests", $ketuaRt2)->assertForbidden();

        foreach ([Role::BENDAHARA, Role::WARGA] as $role) {
            $headers = $this->bearerFor($this->userWithRole($rt1, $role));
            $this->getJson("/api/v1/document-requests/{$id}", $headers)->assertForbidden();
            $this->getJson("/api/v1/units/{$rt1->id}/document-requests", $headers)->assertForbidden();
            $this->postJson("/api/v1/document-requests/{$id}/transition", ['status' => 'approved'], $headers)->assertForbidden();
            $this->postJson("/api/v1/document-requests/{$id}/cancel", [], $headers)->assertForbidden();
        }

        $ketuaRw = $this->bearerFor($this->userWithRole($rw, Role::KETUA_RW));
        $this->getJson("/api/v1/units/{$rw->id}/document-requests", $ketuaRw)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/v1/document-requests/{$id}/transition", ['status' => 'approved'], $ketuaRw)->assertForbidden();

        $this->assertSame('submitted', DocumentRequest::find($id)->status);
    }

    public function test_officer_cannot_process_own_request(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        [$ketua, $resident] = $this->linkedWarga($rt);
        $this->grant($ketua, $rt, Role::KETUA_RT);
        $id = $this->submit($ketua, $resident, 'skck');

        $this->postJson("/api/v1/document-requests/{$id}/transition", ['status' => 'approved'], $this->bearerFor($ketua))->assertForbidden();
    }

    public function test_issued_letter_opens_via_signed_link_with_nik_and_is_audited(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        [$warga, $resident] = $this->linkedWarga($rt);
        $id = $this->submit($warga, $resident, 'usaha', ['nama_usaha' => 'Warung Sari', 'jenis_usaha' => 'Kelontong', 'alamat_usaha' => 'Jl. Melati 2']);
        $wargaHeaders = $this->bearerFor($warga);

        $this->postJson("/api/v1/document-requests/{$id}/letter-link", [], $wargaHeaders)->assertConflict();
        $this->postJson("/api/v1/document-requests/{$id}/transition", ['status' => 'approved'], $ketua)->assertOk();
        $this->postJson("/api/v1/document-requests/{$id}/transition", ['status' => 'issued'], $ketua)->assertOk();

        $url = $this->postJson("/api/v1/document-requests/{$id}/letter-link", [], $wargaHeaders)->assertOk()->json('data.url');

        $html = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertStringContainsString(self::NIK, $html);
        $this->assertStringContainsString('Warung Sari', $html);
        $this->assertStringContainsString(DocumentRequest::find($id)->letter_number, $html);
        $this->assertTrue(AuditLog::query()->where('action', 'document_request.letter_viewed')->where('entity_id', $id)->exists());

        $this->get($url.'x')->assertForbidden();
        $this->get(route('documents.letter', ['documentRequest' => $id]))->assertForbidden();
        $this->get(URL::temporarySignedRoute('documents.letter', now()->subMinute(), ['documentRequest' => $id]))->assertForbidden();
    }

    public function test_form_data_is_always_a_json_object(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        [$warga, $resident] = $this->linkedWarga($rt);
        $id = $this->submit($warga, $resident, 'pengantar_umum');

        // Jenis surat tanpa isian tambahan: harus {} (bukan []) agar aplikasi bisa membacanya sebagai map.
        $response = $this->getJson("/api/v1/document-requests/{$id}", $this->bearerFor($warga))->assertOk();
        $this->assertStringContainsString('"form_data":{}', $response->getContent());

        $this->postJson("/api/v1/document-requests/{$id}/transition", ['status' => 'revision_requested', 'notes' => 'Perjelas keperluan'], $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT)))
            ->assertOk()
            ->assertJsonPath('data.status', 'revision_requested');
        $this->assertStringContainsString('"form_data":{}', $this->getJson("/api/v1/document-requests/{$id}", $this->bearerFor($warga))->getContent());
    }

    public function test_requester_cancels_only_while_open(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        [$warga, $resident] = $this->linkedWarga($rt);
        $id = $this->submit($warga, $resident, 'skck');

        $this->postJson("/api/v1/document-requests/{$id}/cancel", [], $this->bearerFor($warga))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/document-requests/{$id}/transition", ['status' => 'approved'], $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT)))
            ->assertConflict();
    }

    /** @return array{0: User, 1: Resident} */
    private function linkedWarga(AdministrativeUnit $unit): array
    {
        $user = $this->userWithRole($unit, Role::WARGA);
        $resident = $this->residentIn($unit, ['user_id' => $user->id, 'full_name' => 'Siti Pemohon']);
        $resident->forceFill(app(PopulationPii::class)->columns(self::NIK, PopulationPii::NIK, 'nik'))->save();

        return [$user, $resident];
    }

    private function submit(User $user, Resident $resident, string $type, array $formData = []): string
    {
        return $this->postJson('/api/v1/document-requests', [
            'document_type_id' => $this->type($type)->id,
            'resident_id' => $resident->id,
            'purpose' => 'Keperluan administrasi',
            'form_data' => $formData,
        ], $this->bearerFor($user))->assertCreated()->json('data.id');
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::query()->where('code', $code)->sole();
    }
}
