<?php

namespace Tests\Feature\Population;

use App\Models\AuditLog;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentMovement;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResidentTest extends TestCase
{
    private const NIK = '3201010101017890';

    public function test_ketua_rt_adds_resident_with_masked_nik_and_move_in_movement(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $household = Household::query()->create(['unit_id' => $rt->id, 'address_text' => 'Jl. Melati 1', 'status' => 'active']);

        $response = $this->postJson("/api/v1/households/{$household->id}/residents", [
            'full_name' => 'Siti Aminah',
            'nik' => self::NIK,
            'residency_status' => 'permanent',
            'is_household_head' => true,
            'moved_in_at' => '2026-01-15',
        ], $this->bearerFor($ketua))->assertCreated();

        $response->assertJsonPath('data.nik_masked', '************7890')->assertJsonPath('data.is_household_head', true);
        $this->assertStringNotContainsString(self::NIK, $response->getContent());

        $id = $response->json('data.id');
        $this->assertSame($id, $household->fresh()->head_resident_id);

        $movement = ResidentMovement::query()->where('resident_id', $id)->sole();
        $this->assertSame(ResidentMovement::MOVE_IN, $movement->movement_type);
        $this->assertSame($household->id, $movement->to_household_id);
        $this->assertSame('2026-01-15', $movement->effective_date->toDateString());
        $this->assertSame($ketua->id, $movement->recorded_by);

        $this->assertTrue(AuditLog::query()->where('action', 'resident.created')->where('entity_id', $id)->exists());
        $this->assertNikNeverStoredInPlaintext();
    }

    public function test_list_and_detail_never_expose_full_nik(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::SEKRETARIS));
        $id = $this->createResidentWithNik($rt, $headers);

        foreach (["/api/v1/units/{$rt->id}/residents", "/api/v1/residents/{$id}", '/api/v1/households/'.Resident::find($id)->household_id] as $url) {
            $response = $this->getJson($url, $headers)->assertOk();
            $this->assertStringNotContainsString(self::NIK, $response->getContent(), $url);
            $this->assertStringContainsString('************7890', $response->getContent(), $url);
            $this->assertStringNotContainsString('nik_ciphertext', $response->getContent(), $url);
        }
    }

    public function test_reveal_nik_is_restricted_and_audited(): void
    {
        ['rw' => $rw, 'rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $id = $this->createResidentWithNik($rt, $headers);

        $this->postJson("/api/v1/residents/{$id}/nik/reveal", ['reason' => 'Verifikasi surat'], $headers)
            ->assertOk()
            ->assertJsonPath('data.nik', self::NIK);

        $log = AuditLog::query()->where('action', 'resident.nik_revealed')->sole();
        $this->assertSame(['reason' => 'Verifikasi surat'], $log->metadata);

        foreach ([[$rw, Role::KETUA_RW], [$rt, Role::BENDAHARA], [$rt, Role::WARGA]] as [$unit, $role]) {
            $this->postJson("/api/v1/residents/{$id}/nik/reveal", ['reason' => 'x'], $this->bearerFor($this->userWithRole($unit, $role)))
                ->assertForbidden();
        }
        $this->assertSame(1, AuditLog::query()->where('action', 'resident.nik_revealed')->count());
    }

    public function test_nik_lookup_is_scoped_to_unit(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $headers1 = $this->bearerFor($this->userWithRole($rt1, Role::KETUA_RT));
        $id = $this->createResidentWithNik($rt1, $headers1);

        $this->postJson("/api/v1/units/{$rt1->id}/residents/lookup-nik", ['nik' => self::NIK], $headers1)
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $headers2 = $this->bearerFor($this->userWithRole($rt2, Role::KETUA_RT));
        $this->postJson("/api/v1/units/{$rt2->id}/residents/lookup-nik", ['nik' => self::NIK], $headers2)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/units/{$rt1->id}/residents/lookup-nik", ['nik' => self::NIK], $headers2)->assertForbidden();
    }

    public function test_duplicate_nik_is_rejected(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $this->createResidentWithNik($rt, $headers);
        $household = Household::query()->create(['unit_id' => $rt->id, 'address_text' => 'B', 'status' => 'active']);

        $this->postJson("/api/v1/households/{$household->id}/residents", ['full_name' => 'Dobel', 'nik' => self::NIK, 'residency_status' => 'permanent'], $headers)
            ->assertJsonValidationErrors('nik');
    }

    public function test_cross_rt_idor_is_forbidden(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt1, Role::KETUA_RT));
        $victim = $this->residentIn($rt2, ['full_name' => 'Warga RT Lain']);

        $this->getJson("/api/v1/units/{$rt2->id}/residents", $headers)->assertForbidden();
        $this->getJson("/api/v1/residents/{$victim->id}", $headers)->assertForbidden();
        $this->getJson("/api/v1/residents/{$victim->id}/movements", $headers)->assertForbidden();
        $this->patchJson("/api/v1/residents/{$victim->id}", ['full_name' => 'Diubah'], $headers)->assertForbidden();
        $this->deleteJson("/api/v1/residents/{$victim->id}", ['reason' => 'x'], $headers)->assertForbidden();
        $this->postJson("/api/v1/households/{$victim->household_id}/residents", ['full_name' => 'Susup', 'residency_status' => 'permanent'], $headers)->assertForbidden();
        $this->postJson("/api/v1/residents/{$victim->id}/movements", ['movement_type' => 'death', 'effective_date' => '2026-10-01'], $headers)->assertForbidden();

        $fresh = $victim->fresh();
        $this->assertSame('Warga RT Lain', $fresh->full_name);
        $this->assertTrue($fresh->is_alive);
        $this->assertSame(1, Resident::query()->count());
        $this->assertSame(0, ResidentMovement::query()->count());
    }

    public function test_sekretaris_may_write_bendahara_and_warga_may_not(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $resident = $this->residentIn($rt);

        $this->patchJson("/api/v1/residents/{$resident->id}", ['occupation' => 'Guru'], $this->bearerFor($this->userWithRole($rt, Role::SEKRETARIS)))
            ->assertOk()
            ->assertJsonPath('data.occupation', 'Guru');

        foreach ([Role::BENDAHARA, Role::WARGA] as $role) {
            $headers = $this->bearerFor($this->userWithRole($rt, $role));
            $this->getJson("/api/v1/units/{$rt->id}/residents", $headers)->assertForbidden();
            $this->getJson("/api/v1/residents/{$resident->id}", $headers)->assertForbidden();
            $this->patchJson("/api/v1/residents/{$resident->id}", ['occupation' => 'Petani'], $headers)->assertForbidden();
        }
        $this->assertSame('Guru', $resident->fresh()->occupation);
    }

    public function test_update_cannot_change_address_or_status_directly(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $resident = $this->residentIn($rt);
        $other = $this->residentIn($rt)->household;

        $this->patchJson("/api/v1/residents/{$resident->id}", [
            'household_id' => $other->id,
            'residency_status' => 'moved_out',
            'is_alive' => false,
        ], $headers)->assertJsonValidationErrors(['household_id', 'residency_status', 'is_alive']);

        $this->assertSame($resident->household_id, $resident->fresh()->household_id);
    }

    public function test_biodata_update_is_audited_without_nik_value(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $resident = $this->residentIn($rt, ['full_name' => 'Nama Lama']);

        $this->patchJson("/api/v1/residents/{$resident->id}", ['full_name' => 'Nama Baru', 'nik' => self::NIK], $headers)
            ->assertOk()
            ->assertJsonPath('data.nik_masked', '************7890');

        $log = AuditLog::query()->where('action', 'resident.updated')->sole();
        $this->assertSame(['Nama Lama', 'Nama Baru'], $log->metadata['changes']['full_name']);
        $this->assertSame(['nik'], $log->metadata['pii_fields_changed']);
        $this->assertNikNeverStoredInPlaintext();
    }

    public function test_soft_delete_requires_reason_and_releases_nik(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $id = $this->createResidentWithNik($rt, $headers);

        $this->deleteJson("/api/v1/residents/{$id}", [], $headers)->assertJsonValidationErrors('reason');
        $this->deleteJson("/api/v1/residents/{$id}", ['reason' => 'Salah input'], $headers)->assertOk();

        $this->assertSoftDeleted('residents', ['id' => $id]);
        $this->createResidentWithNik($rt, $headers);
    }

    public function test_linking_search_still_works_for_unlinked_residents(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $this->residentIn($rt, ['full_name' => 'Budi Aktif']);
        $this->residentIn($rt, ['full_name' => 'Budi Pindah', 'residency_status' => Resident::STATUS_MOVED_OUT]);

        $response = $this->getJson("/api/v1/units/{$rt->id}/residents?unlinked=1&q=budi", $headers)->assertOk();
        $this->assertSame(['Budi Aktif'], array_column($response->json('data'), 'full_name'));
        $this->assertSame('Jl. Uji No. 1', $response->json('data.0.address_text'));
        $this->assertFalse($response->json('data.0.is_linked'));
    }

    private function createResidentWithNik($unit, array $headers): string
    {
        $household = Household::query()->create(['unit_id' => $unit->id, 'address_text' => 'Jl. Melati 1', 'status' => 'active']);

        return $this->postJson("/api/v1/households/{$household->id}/residents", [
            'full_name' => 'Siti Aminah',
            'nik' => self::NIK,
            'residency_status' => 'permanent',
        ], $headers)->assertCreated()->json('data.id');
    }

    private function assertNikNeverStoredInPlaintext(): void
    {
        $stored = DB::table('residents')->whereNotNull('nik_ciphertext')->selectRaw("encode(nik_ciphertext, 'escape') AS c, encode(nik_lookup_hmac, 'hex') AS h")->get();
        $this->assertNotEmpty($stored);
        foreach ($stored as $row) {
            $this->assertStringNotContainsString(self::NIK, $row->c);
            $this->assertNotSame(hash('sha256', self::NIK), $row->h);
        }
        $this->assertFalse(DB::table('audit_logs')->whereRaw('metadata::text LIKE ?', ['%'.self::NIK.'%'])->exists());
    }
}
