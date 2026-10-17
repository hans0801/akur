<?php

namespace Tests\Feature\Population;

use App\Models\AuditLog;
use App\Models\Resident;
use App\Models\ResidentMovement;
use App\Models\Role;
use Tests\TestCase;

class ResidentMovementTest extends TestCase
{
    public function test_move_house_records_history_instead_of_overwriting(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $resident = $this->residentIn($rt);
        $from = $resident->household;
        $from->update(['head_resident_id' => $resident->id]);
        $resident->update(['is_household_head' => true]);
        $to = $this->residentIn($rt)->household;

        $this->postJson("/api/v1/residents/{$resident->id}/movements", [
            'movement_type' => 'move_house',
            'to_household_id' => $to->id,
            'effective_date' => '2026-10-01',
            'reason' => 'Menikah',
        ], $this->bearerFor($ketua))
            ->assertCreated()
            ->assertJsonPath('resident.household_id', $to->id)
            ->assertJsonPath('resident.is_household_head', false);

        $movement = ResidentMovement::query()->where('resident_id', $resident->id)->sole();
        $this->assertSame([$from->id, $to->id, $ketua->id], [$movement->from_household_id, $movement->to_household_id, $movement->recorded_by]);
        $this->assertNull($from->fresh()->head_resident_id);

        $log = AuditLog::query()->where('action', 'resident.movement_recorded')->sole();
        $this->assertSame('move_house', $log->metadata['movement_type']);
        $this->assertArrayNotHasKey('reason', $log->metadata);
    }

    public function test_move_out_then_move_in_keeps_full_history(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::SEKRETARIS));
        $resident = $this->residentIn($rt);
        $household = $resident->household;

        $this->postJson("/api/v1/residents/{$resident->id}/movements", ['movement_type' => 'move_out', 'effective_date' => '2026-03-01'], $headers)
            ->assertCreated()
            ->assertJsonPath('resident.residency_status', Resident::STATUS_MOVED_OUT)
            ->assertJsonPath('resident.moved_out_at', '2026-03-01');

        $this->postJson("/api/v1/residents/{$resident->id}/movements", ['movement_type' => 'move_out', 'effective_date' => '2026-03-02'], $headers)
            ->assertConflict();

        $this->postJson("/api/v1/residents/{$resident->id}/movements", [
            'movement_type' => 'move_in', 'to_household_id' => $household->id, 'effective_date' => '2026-09-01', 'residency_status' => 'contract',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('resident.residency_status', 'contract')
            ->assertJsonPath('resident.moved_out_at', null);

        $this->getJson("/api/v1/residents/{$resident->id}/movements", $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.movement_type', 'move_in')
            ->assertJsonPath('data.1.movement_type', 'move_out');
    }

    public function test_death_is_final_except_for_correction(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $resident = $this->residentIn($rt);

        $this->postJson("/api/v1/residents/{$resident->id}/movements", ['movement_type' => 'death', 'effective_date' => '2026-10-10'], $headers)
            ->assertCreated()
            ->assertJsonPath('resident.is_alive', false)
            ->assertJsonPath('resident.residency_status', Resident::STATUS_DECEASED);

        $this->postJson("/api/v1/residents/{$resident->id}/movements", ['movement_type' => 'move_out', 'effective_date' => '2026-10-11'], $headers)
            ->assertConflict();

        $this->postJson("/api/v1/residents/{$resident->id}/movements", ['movement_type' => 'correction', 'effective_date' => '2026-10-11', 'residency_status' => 'permanent'], $headers)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/residents/{$resident->id}/movements", [
            'movement_type' => 'correction', 'effective_date' => '2026-10-11', 'residency_status' => 'permanent', 'reason' => 'Salah catat',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('resident.is_alive', true);

        $this->assertSame(2, ResidentMovement::query()->where('resident_id', $resident->id)->count());
    }

    public function test_cannot_move_resident_into_another_rt_without_access_there(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt1, Role::KETUA_RT));
        $resident = $this->residentIn($rt1);
        $foreign = $this->residentIn($rt2)->household;

        $this->postJson("/api/v1/residents/{$resident->id}/movements", [
            'movement_type' => 'move_house', 'to_household_id' => $foreign->id, 'effective_date' => '2026-10-01',
        ], $headers)->assertForbidden();

        $this->assertSame($resident->household_id, $resident->fresh()->household_id);
        $this->assertSame(0, ResidentMovement::query()->count());
    }

    public function test_ketua_rw_may_move_across_rts_only_with_write_role(): void
    {
        ['rw' => $rw, 'rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $resident = $this->residentIn($rt1);
        $target = $this->residentIn($rt2)->household;
        $payload = ['movement_type' => 'move_house', 'to_household_id' => $target->id, 'effective_date' => '2026-10-01'];

        $this->postJson("/api/v1/residents/{$resident->id}/movements", $payload, $this->bearerFor($this->userWithRole($rw, Role::KETUA_RW)))
            ->assertForbidden();

        $this->postJson("/api/v1/residents/{$resident->id}/movements", $payload, $this->bearerFor($this->userWithRole($rw, Role::SUPER_ADMIN)))
            ->assertCreated()
            ->assertJsonPath('resident.unit_id', $rt2->id);
    }

    public function test_bendahara_and_warga_cannot_record_movements(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $resident = $this->residentIn($rt);

        foreach ([Role::BENDAHARA, Role::WARGA] as $role) {
            $this->postJson("/api/v1/residents/{$resident->id}/movements", ['movement_type' => 'death', 'effective_date' => '2026-10-01'], $this->bearerFor($this->userWithRole($rt, $role)))
                ->assertForbidden();
        }
        $this->assertTrue($resident->fresh()->is_alive);
    }
}
