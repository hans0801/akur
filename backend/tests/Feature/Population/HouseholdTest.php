<?php

namespace Tests\Feature\Population;

use App\Models\AuditLog;
use App\Models\Household;
use App\Models\Role;
use App\Models\Street;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HouseholdTest extends TestCase
{
    private const KK = '3201019876543456';

    public function test_ketua_rt_creates_household_with_masked_kk(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $street = Street::query()->create(['unit_id' => $rt->id, 'name' => 'Jl. Mawar']);

        $response = $this->postJson("/api/v1/units/{$rt->id}/households", [
            'kk_number' => self::KK,
            'street_id' => $street->id,
            'address_text' => 'Jl. Mawar No. 3',
        ], $this->bearerFor($ketua))->assertCreated();

        $response->assertJsonPath('data.kk_masked', '************3456')->assertJsonPath('data.has_kk', true);
        $this->assertStringNotContainsString(self::KK, $response->getContent());

        $stored = DB::table('households')->where('id', $response->json('data.id'))->selectRaw("encode(kk_number_ciphertext, 'escape') AS c")->value('c');
        $this->assertStringNotContainsString(self::KK, $stored);

        $log = AuditLog::query()->where('action', 'household.created')->sole();
        $this->assertSame($rt->id, $log->unit_id);
        $this->assertStringNotContainsString(self::KK, json_encode($log->metadata));
    }

    public function test_duplicate_kk_is_rejected(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt1->parent, Role::SUPER_ADMIN));

        $this->postJson("/api/v1/units/{$rt1->id}/households", ['kk_number' => self::KK, 'address_text' => 'A'], $headers)->assertCreated();
        $this->postJson("/api/v1/units/{$rt2->id}/households", ['kk_number' => self::KK, 'address_text' => 'B'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kk_number');
    }

    public function test_cross_rt_access_is_forbidden(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt1, Role::KETUA_RT));
        $other = $this->residentIn($rt2)->household;

        $this->getJson("/api/v1/units/{$rt2->id}/households", $headers)->assertForbidden();
        $this->postJson("/api/v1/units/{$rt2->id}/households", ['address_text' => 'X'], $headers)->assertForbidden();
        $this->getJson("/api/v1/households/{$other->id}", $headers)->assertForbidden();
        $this->patchJson("/api/v1/households/{$other->id}", ['address_text' => 'Diubah'], $headers)->assertForbidden();
        $this->postJson("/api/v1/households/{$other->id}/kk/reveal", ['reason' => 'cek'], $headers)->assertForbidden();

        $this->assertSame('Jl. Uji No. 1', $other->fresh()->address_text);
    }

    public function test_street_or_house_from_another_unit_is_rejected(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt1, Role::SEKRETARIS));
        $foreignStreet = Street::query()->create(['unit_id' => $rt2->id, 'name' => 'Jl. Lain']);

        $this->postJson("/api/v1/units/{$rt1->id}/households", ['street_id' => $foreignStreet->id, 'address_text' => 'X'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('street_id');
    }

    public function test_ketua_rw_reads_rts_below_but_cannot_write(): void
    {
        ['rw' => $rw, 'rt2' => $rt2] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rw, Role::KETUA_RW));
        $household = $this->residentIn($rt2)->household;

        $this->getJson("/api/v1/units/{$rw->id}/households", $headers)->assertOk()->assertJsonPath('data.0.id', $household->id);
        $this->getJson("/api/v1/households/{$household->id}", $headers)->assertOk();
        $this->patchJson("/api/v1/households/{$household->id}", ['address_text' => 'Diubah'], $headers)->assertForbidden();
        $this->postJson("/api/v1/units/{$rt2->id}/households", ['address_text' => 'X'], $headers)->assertForbidden();
    }

    public function test_bendahara_and_warga_have_no_access(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $household = $this->residentIn($rt)->household;

        foreach ([Role::BENDAHARA, Role::WARGA] as $role) {
            $headers = $this->bearerFor($this->userWithRole($rt, $role));

            $this->getJson("/api/v1/units/{$rt->id}/households", $headers)->assertForbidden();
            $this->getJson("/api/v1/households/{$household->id}", $headers)->assertForbidden();
            $this->postJson("/api/v1/units/{$rt->id}/households", ['address_text' => 'X'], $headers)->assertForbidden();
        }
    }

    public function test_address_change_is_audited_with_old_and_new_values(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::SEKRETARIS));
        $household = $this->residentIn($rt)->household;

        $this->patchJson("/api/v1/households/{$household->id}", ['address_text' => 'Jl. Baru No. 9', 'kk_number' => self::KK], $headers)
            ->assertOk()
            ->assertJsonPath('data.address_text', 'Jl. Baru No. 9')
            ->assertJsonPath('data.kk_masked', '************3456');

        $log = AuditLog::query()->where('action', 'household.updated')->sole();
        $this->assertSame(['Jl. Uji No. 1', 'Jl. Baru No. 9'], $log->metadata['changes']['address_text']);
        $this->assertSame(['kk_number'], $log->metadata['pii_fields_changed']);
        $this->assertStringNotContainsString(self::KK, json_encode($log->metadata));
    }

    public function test_head_must_be_member_of_household(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $member = $this->residentIn($rt);
        $outsider = $this->residentIn($rt);

        $this->patchJson("/api/v1/households/{$member->household_id}", ['head_resident_id' => $outsider->id], $headers)
            ->assertJsonValidationErrors('head_resident_id');

        $this->patchJson("/api/v1/households/{$member->household_id}", ['head_resident_id' => $member->id], $headers)
            ->assertOk()
            ->assertJsonPath('data.head_resident_id', $member->id);
        $this->assertTrue($member->fresh()->is_household_head);
    }

    public function test_reveal_kk_requires_reason_and_is_audited(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::SEKRETARIS));
        $id = $this->postJson("/api/v1/units/{$rt->id}/households", ['kk_number' => self::KK, 'address_text' => 'A'], $headers)->json('data.id');

        $this->postJson("/api/v1/households/{$id}/kk/reveal", [], $headers)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/households/{$id}/kk/reveal", ['reason' => 'Surat pengantar'], $headers)
            ->assertOk()
            ->assertJsonPath('data.kk_number', self::KK)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertTrue(AuditLog::query()->where('action', 'household.kk_revealed')->where('entity_id', $id)->exists());
        $this->assertSame(1, Household::query()->count());
    }
}
