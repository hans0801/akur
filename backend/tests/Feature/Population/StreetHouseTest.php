<?php

namespace Tests\Feature\Population;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Street;
use Tests\TestCase;

class StreetHouseTest extends TestCase
{
    public function test_ketua_rt_manages_streets_and_houses(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));

        $streetId = $this->postJson("/api/v1/units/{$rt->id}/streets", ['name' => 'Jl. Kenanga'], $headers)->assertCreated()->json('data.id');
        $house = ['street_id' => $streetId, 'house_number' => '12', 'occupancy_status' => 'occupied'];
        $houseId = $this->postJson("/api/v1/units/{$rt->id}/houses", $house, $headers)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/units/{$rt->id}/houses", $house, $headers)->assertJsonValidationErrors('house_number');
        $this->postJson("/api/v1/units/{$rt->id}/houses", [...$house, 'block_label' => 'B'], $headers)->assertCreated();

        $this->patchJson("/api/v1/houses/{$houseId}", ['occupancy_status' => 'vacant'], $headers)->assertOk()->assertJsonPath('data.occupancy_status', 'vacant');
        $this->patchJson("/api/v1/streets/{$streetId}", ['is_active' => false], $headers)->assertOk();

        $this->getJson("/api/v1/units/{$rt->id}/streets", $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/units/{$rt->id}/streets?include_inactive=1", $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/units/{$rt->id}/houses?street_id={$streetId}", $headers)->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(
            ['house.created', 'house.created', 'house.updated', 'street.created', 'street.updated'],
            AuditLog::query()->orderBy('action')->pluck('action')->all(),
        );
    }

    public function test_cross_rt_street_and_house_access_is_forbidden(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt1, Role::KETUA_RT));
        $foreign = Street::query()->create(['unit_id' => $rt2->id, 'name' => 'Jl. Asing']);
        $foreignHouse = $foreign->houses()->create(['house_number' => '1', 'occupancy_status' => 'occupied']);

        $this->getJson("/api/v1/units/{$rt2->id}/streets", $headers)->assertForbidden();
        $this->patchJson("/api/v1/streets/{$foreign->id}", ['name' => 'Dibajak'], $headers)->assertForbidden();
        $this->patchJson("/api/v1/houses/{$foreignHouse->id}", ['house_number' => '99'], $headers)->assertForbidden();
        $this->deleteJson("/api/v1/houses/{$foreignHouse->id}", [], $headers)->assertForbidden();

        // Membuat rumah di unit sendiri tetapi menunjuk jalan RT lain.
        $this->postJson("/api/v1/units/{$rt1->id}/houses", ['street_id' => $foreign->id, 'house_number' => '2', 'occupancy_status' => 'occupied'], $headers)
            ->assertJsonValidationErrors('street_id');

        $this->assertSame('Jl. Asing', $foreign->fresh()->name);
    }

    public function test_bendahara_and_warga_cannot_manage_streets(): void
    {
        ['rt1' => $rt] = $this->unitTree();

        foreach ([Role::BENDAHARA, Role::WARGA] as $role) {
            $headers = $this->bearerFor($this->userWithRole($rt, $role));
            $this->getJson("/api/v1/units/{$rt->id}/streets", $headers)->assertForbidden();
            $this->postJson("/api/v1/units/{$rt->id}/streets", ['name' => 'Jl. X'], $headers)->assertForbidden();
        }
    }
}
