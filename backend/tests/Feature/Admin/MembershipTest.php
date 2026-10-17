<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMembership;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    public function test_ketua_rt_can_assign_bendahara_in_own_rt(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $target = User::factory()->create();

        $this->postJson('/api/v1/admin/memberships', [
            'user_id' => $target->id,
            'unit_id' => $rt->id,
            'role' => Role::BENDAHARA,
            'reason' => 'Hasil rapat RT',
        ], $this->bearerFor($ketua))->assertCreated();

        $this->assertTrue($target->memberships()->effective()->where('role_id', Role::idFor(Role::BENDAHARA))->exists());
        $log = AuditLog::query()->where('action', 'membership.granted')->sole();
        $this->assertSame('bendahara', $log->metadata['role']);
        $this->assertSame($ketua->id, $log->actor_user_id);
    }

    public function test_ketua_rt_cannot_escalate_to_ketua_or_super_admin(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $target = User::factory()->create();

        foreach ([Role::KETUA_RT, Role::KETUA_RW, Role::SUPER_ADMIN] as $role) {
            $this->postJson('/api/v1/admin/memberships', [
                'user_id' => $target->id, 'unit_id' => $rt->id, 'role' => $role, 'reason' => 'x',
            ], $headers)->assertForbidden();
        }
    }

    public function test_cannot_assign_role_in_another_rt(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt1, Role::KETUA_RT));

        $this->postJson('/api/v1/admin/memberships', [
            'user_id' => User::factory()->create()->id, 'unit_id' => $rt2->id, 'role' => Role::WARGA, 'reason' => 'x',
        ], $headers)->assertForbidden();
    }

    public function test_cannot_change_own_roles(): void
    {
        ['rw' => $rw] = $this->unitTree();
        $admin = $this->userWithRole($rw, Role::SUPER_ADMIN);

        $this->postJson('/api/v1/admin/memberships', [
            'user_id' => $admin->id, 'unit_id' => $rw->id, 'role' => Role::KETUA_RW, 'reason' => 'x',
        ], $this->bearerFor($admin))->assertForbidden();
    }

    public function test_pending_account_cannot_receive_a_role(): void
    {
        ['rw' => $rw] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rw, Role::SUPER_ADMIN));

        $this->postJson('/api/v1/admin/memberships', [
            'user_id' => User::factory()->pending()->create()->id, 'unit_id' => $rw->id, 'role' => Role::KETUA_RW, 'reason' => 'x',
        ], $headers)->assertUnprocessable();
    }

    public function test_ketua_rw_cannot_assign_management_roles_at_rw_level(): void
    {
        ['rw' => $rw, 'rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rw, Role::KETUA_RW));
        $target = User::factory()->create();

        $this->postJson('/api/v1/admin/memberships', [
            'user_id' => $target->id, 'unit_id' => $rw->id, 'role' => Role::BENDAHARA, 'reason' => 'x',
        ], $headers)->assertForbidden();

        $this->postJson('/api/v1/admin/memberships', [
            'user_id' => $target->id, 'unit_id' => $rt->id, 'role' => Role::KETUA_RT, 'reason' => 'x',
        ], $headers)->assertCreated();
    }

    public function test_revoked_role_no_longer_grants_access(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $sekretaris = $this->userWithRole($rt, Role::SEKRETARIS);
        $membership = $sekretaris->memberships()->sole();
        $sekretarisHeaders = $this->bearerFor($sekretaris);

        $this->getJson('/api/v1/admin/pending-users', $sekretarisHeaders)->assertOk();

        $this->deleteJson("/api/v1/admin/memberships/{$membership->id}", ['reason' => 'Masa jabatan selesai'], $this->bearerFor($ketua))
            ->assertOk();
        $this->assertSame(UserMembership::STATUS_REVOKED, $membership->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'membership.revoked')->exists());

        $this->getJson('/api/v1/admin/pending-users', $sekretarisHeaders)->assertForbidden();
    }

    public function test_expired_membership_is_not_effective(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $sekretaris = $this->userWithRole($rt, Role::SEKRETARIS);
        $sekretaris->memberships()->update(['expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/admin/pending-users', $this->bearerFor($sekretaris))->assertForbidden();
    }
}
