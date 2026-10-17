<?php

namespace Tests\Feature\Admin;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class AccountLinkingTest extends TestCase
{
    public function test_ketua_rt_links_pending_account_to_resident_in_own_rt(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $pending = User::factory()->pending()->create();
        $resident = $this->residentIn($rt);

        $this->postJson("/api/v1/admin/pending-users/{$pending->id}/link", ['resident_id' => $resident->id], $this->bearerFor($ketua))
            ->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $pending->fresh()->account_status);
        $this->assertSame($pending->id, $resident->fresh()->user_id);
        $this->assertTrue($pending->memberships()->where('unit_id', $rt->id)->where('role_id', Role::idFor(Role::WARGA))->exists());

        $log = AuditLog::query()->where('action', 'user.linked_to_resident')->sole();
        $this->assertSame($ketua->id, $log->actor_user_id);
        $this->assertSame($rt->id, $log->unit_id);

        $this->assertTrue(AppNotification::query()->where('user_id', $pending->id)->where('type', 'account.verified')->exists());
    }

    public function test_ketua_rt_cannot_link_resident_of_another_rt(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $ketua = $this->userWithRole($rt1, Role::KETUA_RT);
        $pending = User::factory()->pending()->create();
        $resident = $this->residentIn($rt2);

        $this->postJson("/api/v1/admin/pending-users/{$pending->id}/link", ['resident_id' => $resident->id], $this->bearerFor($ketua))
            ->assertForbidden();

        $this->assertNull($resident->fresh()->user_id);
        $this->assertSame(User::STATUS_PENDING, $pending->fresh()->account_status);
    }

    public function test_ketua_rw_scope_covers_rts_below(): void
    {
        ['rw' => $rw, 'rt2' => $rt2] = $this->unitTree();
        $ketuaRw = $this->userWithRole($rw, Role::KETUA_RW);
        $pending = User::factory()->pending()->create();
        $resident = $this->residentIn($rt2);

        $this->postJson("/api/v1/admin/pending-users/{$pending->id}/link", ['resident_id' => $resident->id], $this->bearerFor($ketuaRw))
            ->assertOk();
    }

    public function test_bendahara_and_warga_cannot_link_or_list_pending_accounts(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $pending = User::factory()->pending()->create();
        $resident = $this->residentIn($rt);

        foreach ([Role::BENDAHARA, Role::WARGA] as $role) {
            $headers = $this->bearerFor($this->userWithRole($rt, $role));

            $this->getJson('/api/v1/admin/pending-users', $headers)->assertForbidden();
            $this->postJson("/api/v1/admin/pending-users/{$pending->id}/link", ['resident_id' => $resident->id], $headers)->assertForbidden();
        }
    }

    public function test_cannot_link_resident_that_already_has_an_account(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $resident = $this->residentIn($rt, ['user_id' => User::factory()->create()->id]);
        $pending = User::factory()->pending()->create();

        $this->postJson("/api/v1/admin/pending-users/{$pending->id}/link", ['resident_id' => $resident->id], $this->bearerFor($ketua))
            ->assertConflict();
    }

    public function test_cannot_link_an_account_that_is_not_pending(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $active = User::factory()->create();

        $this->postJson("/api/v1/admin/pending-users/{$active->id}/link", ['resident_id' => $this->residentIn($rt)->id], $this->bearerFor($ketua))
            ->assertConflict();
    }

    public function test_reject_disables_account_and_revokes_sessions(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $pending = User::factory()->pending()->create();
        $pendingHeaders = $this->bearerFor($pending);

        $this->postJson("/api/v1/admin/pending-users/{$pending->id}/reject", ['reason' => 'Bukan warga RT ini'], $this->bearerFor($ketua))
            ->assertOk();

        $this->assertSame(User::STATUS_DISABLED, $pending->fresh()->account_status);
        $this->getJson('/api/v1/me', $pendingHeaders)->assertUnauthorized();
    }

    public function test_resident_search_is_scoped_to_unit_and_hides_nik(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $ketua = $this->userWithRole($rt1, Role::KETUA_RT);
        $this->residentIn($rt1, ['full_name' => 'Budi Satu']);
        $this->residentIn($rt2, ['full_name' => 'Budi Dua']);
        $headers = $this->bearerFor($ketua);

        $response = $this->getJson("/api/v1/units/{$rt1->id}/residents?q=budi", $headers)->assertOk();
        $this->assertSame(['Budi Satu'], array_column($response->json('data'), 'full_name'));
        $this->assertArrayNotHasKey('nik_ciphertext', $response->json('data.0'));

        $this->getJson("/api/v1/units/{$rt2->id}/residents", $headers)->assertForbidden();
    }
}
