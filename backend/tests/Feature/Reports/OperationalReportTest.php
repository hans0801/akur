<?php

namespace Tests\Feature\Reports;

use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\DuesType;
use App\Models\Role;
use Database\Seeders\DocumentTypeSeeder;
use Tests\TestCase;

class OperationalReportTest extends TestCase
{
    public function test_arrears_lists_overdue_households_for_finance_roles_only(): void
    {
        ['rt1' => $rt, 'rt2' => $rt2] = $this->unitTree();
        $bendahara = $this->userWithRole($rt, Role::BENDAHARA);
        $late = $this->residentIn($rt, ['full_name' => 'Pak Telat']);
        $household = $late->household;
        $household->update(['head_resident_id' => $late->id]);
        $type = DuesType::query()->create(['unit_id' => $rt->id, 'name' => 'Iuran', 'default_amount' => 20000, 'frequency' => 'monthly', 'created_by' => $bendahara->id]);
        $past = now()->subMonths(2);
        $this->postJson("/api/v1/dues-types/{$type->id}/periods", [
            'period_code' => $past->format('Y-m'), 'starts_on' => $past->startOfMonth()->toDateString(), 'due_on' => $past->startOfMonth()->addDays(9)->toDateString(),
        ], $this->bearerFor($bendahara))->assertCreated();

        $this->getJson("/api/v1/units/{$rt->id}/reports/arrears", $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT)))
            ->assertOk()
            ->assertJsonPath('data.rows.0.head_name', 'Pak Telat')
            ->assertJsonPath('data.rows.0.outstanding', 20000)
            ->assertJsonPath('data.totals.outstanding.value', 20000)
            ->assertJsonPath('data.totals.outstanding.label', 'Total tunggakan');

        foreach ([Role::SEKRETARIS, Role::WARGA] as $role) {
            $this->getJson("/api/v1/units/{$rt->id}/reports/arrears", $this->bearerFor($this->userWithRole($rt, $role)))->assertForbidden();
        }
        $this->getJson("/api/v1/units/{$rt->id}/reports/arrears", $this->bearerFor($this->userWithRole($rt2, Role::BENDAHARA)))->assertForbidden();
    }

    public function test_documents_and_activities_reports_count_by_status(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        ['rt1' => $rt] = $this->unitTree();
        $sekretaris = $this->userWithRole($rt, Role::SEKRETARIS);
        $warga = $this->userWithRole($rt, Role::WARGA);
        $resident = $this->residentIn($rt, ['user_id' => $warga->id]);
        $skck = DocumentType::query()->where('code', 'skck')->sole();
        foreach (['submitted', 'issued', 'issued'] as $status) {
            DocumentRequest::query()->create([
                'unit_id' => $rt->id, 'requester_user_id' => $warga->id, 'resident_id' => $resident->id, 'document_type_id' => $skck->id,
                'purpose' => 'x', 'form_data' => [], 'status' => $status, 'submitted_at' => now(),
            ]);
        }
        $activity = Activity::query()->create([
            'unit_id' => $rt->id, 'title' => 'Kerja bakti', 'description' => 'x', 'starts_at' => now()->startOfMonth()->addDays(2),
            'location_text' => 'Pos', 'category' => 'kerja_bakti', 'organizer_user_id' => $sekretaris->id, 'status' => 'planned',
        ]);
        ActivityParticipant::query()->create(['activity_id' => $activity->id, 'user_id' => $warga->id, 'resident_id' => $resident->id, 'participation_status' => 'going', 'checked_in_at' => now()]);
        $headers = $this->bearerFor($sekretaris);

        $this->getJson("/api/v1/units/{$rt->id}/reports/documents", $headers)
            ->assertOk()
            ->assertJsonPath('data.rows.0.document_type', 'Pengantar SKCK')
            ->assertJsonPath('data.rows.0.issued', 2)
            ->assertJsonPath('data.rows.0.total', 3);

        $this->getJson("/api/v1/units/{$rt->id}/reports/activities", $headers)
            ->assertOk()
            ->assertJsonPath('data.rows.0.going', 1)
            ->assertJsonPath('data.rows.0.checked_in', 1);

        // Sekretaris tidak berhak atas laporan keuangan, bendahara tidak atas laporan surat.
        $this->getJson("/api/v1/units/{$rt->id}/reports/payments", $headers)->assertForbidden();
        $this->getJson("/api/v1/units/{$rt->id}/reports/documents", $this->bearerFor($this->userWithRole($rt, Role::BENDAHARA)))->assertForbidden();
    }

    public function test_export_respects_report_roles_and_produces_csv(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $bendahara = $this->bearerFor($this->userWithRole($rt, Role::BENDAHARA));
        $sekretaris = $this->bearerFor($this->userWithRole($rt, Role::SEKRETARIS));
        $month = now('Asia/Jakarta')->format('Y-m');

        $this->postJson("/api/v1/units/{$rt->id}/finance-reports/export-link", ['format' => 'csv', 'month' => $month, 'report' => 'payments'], $sekretaris)->assertForbidden();
        $this->postJson("/api/v1/units/{$rt->id}/finance-reports/export-link", ['format' => 'csv', 'month' => $month, 'report' => 'documents'], $bendahara)->assertForbidden();

        $url = $this->postJson("/api/v1/units/{$rt->id}/finance-reports/export-link", ['format' => 'csv', 'month' => $month, 'report' => 'documents'], $sekretaris)
            ->assertOk()->json('data.url');
        $this->assertStringContainsString('Jenis surat', $this->get($url)->assertOk()->streamedContent());

        $html = $this->postJson("/api/v1/units/{$rt->id}/finance-reports/export-link", ['format' => 'html', 'month' => $month, 'report' => 'payments'], $bendahara)->json('data.url');
        $this->get($html)->assertOk()->assertSee('Pembayaran iuran');
    }
}
