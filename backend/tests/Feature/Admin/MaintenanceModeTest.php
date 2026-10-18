<?php

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Tests\Concerns\FakesGoogleTokens;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use FakesGoogleTokens;

    public function test_super_admin_can_log_in_during_maintenance(): void
    {
        $this->fakeGoogleJwks();
        ['rw' => $rw] = $this->unitTree();
        // Login pertama membuat akun; lalu jadikan super_admin dan nyalakan pemeliharaan.
        $first = $this->postJson('/api/v1/auth/google', ['id_token' => $this->googleIdToken(), 'platform' => 'android'])
            ->assertOk()
            ->assertJsonPath('maintenance', false);
        $user = User::query()->findOrFail($first->json('user.id'));
        $user->update(['account_status' => User::STATUS_ACTIVE]);
        $this->grant($user, $rw, Role::SUPER_ADMIN);
        $this->enableMaintenance('Sebentar ya.');

        $login = $this->postJson('/api/v1/auth/google', ['id_token' => $this->googleIdToken(), 'platform' => 'android'])
            ->assertOk()
            ->assertJsonPath('maintenance', true)
            ->assertJsonPath('maintenance_message', 'Sebentar ya.');
        $headers = ['Authorization' => 'Bearer '.$login->json('token'), 'Accept' => 'application/json'];

        $this->putJson('/api/v1/admin/settings/maintenance', ['enabled' => false], $headers)->assertOk();
        $this->assertFalse(AppSetting::maintenance()['enabled']);
    }

    public function test_only_super_admin_can_read_and_change_settings(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));
        $warga = $this->bearerFor($this->userWithRole($rt, Role::WARGA));
        $pending = $this->bearerFor(User::factory()->pending()->create());

        foreach ([$ketua, $warga] as $headers) {
            $this->getJson('/api/v1/admin/settings', $headers)->assertForbidden();
            $this->putJson('/api/v1/admin/settings/maintenance', ['enabled' => true], $headers)->assertForbidden();
        }
        $this->getJson('/api/v1/admin/settings', $pending)->assertForbidden()->assertJsonPath('code', 'account_not_active');
        $this->getJson('/api/v1/admin/settings')->assertUnauthorized();

        $this->assertFalse(AppSetting::maintenance()['enabled']);
    }

    public function test_super_admin_toggles_maintenance_and_it_is_audited(): void
    {
        ['rw' => $rw] = $this->unitTree();
        $admin = $this->userWithRole($rw, Role::SUPER_ADMIN);
        $headers = $this->bearerFor($admin);

        $this->getJson('/api/v1/admin/settings', $headers)->assertOk()->assertJsonPath('data.maintenance.enabled', false);

        $this->putJson('/api/v1/admin/settings/maintenance', ['enabled' => true, 'message' => '  Pembaruan server, selesai pukul 22.00.  '], $headers)
            ->assertOk()
            ->assertJsonPath('data.maintenance.enabled', true)
            ->assertJsonPath('data.maintenance.message', 'Pembaruan server, selesai pukul 22.00.');
        $this->assertSame($admin->id, AppSetting::query()->find(AppSetting::MAINTENANCE)->updated_by);
        $this->assertTrue(AuditLog::query()->where('action', 'settings.maintenance_enabled')->where('actor_user_id', $admin->id)->exists());

        $this->putJson('/api/v1/admin/settings/maintenance', ['enabled' => false], $headers)
            ->assertOk()
            ->assertJsonPath('data.maintenance.enabled', false)
            ->assertJsonPath('data.maintenance.message', null);
        $this->assertTrue(AuditLog::query()->where('action', 'settings.maintenance_disabled')->exists());

        $this->putJson('/api/v1/admin/settings/maintenance', [], $headers)->assertUnprocessable()->assertJsonValidationErrors('enabled');
    }

    public function test_maintenance_blocks_everyone_except_super_admin(): void
    {
        ['rw' => $rw, 'rt1' => $rt] = $this->unitTree();
        $admin = $this->bearerFor($this->userWithRole($rw, Role::SUPER_ADMIN));
        $ketuaRw = $this->bearerFor($this->userWithRole($rw, Role::KETUA_RW));
        $warga = $this->bearerFor($this->userWithRole($rt, Role::WARGA));
        $pending = $this->bearerFor(User::factory()->pending()->create());
        $this->enableMaintenance('Sedang diperbarui.');

        foreach ([$ketuaRw, $warga, $pending] as $headers) {
            $this->getJson('/api/v1/notifications', $headers)
                ->assertStatus(503)
                ->assertJsonPath('code', 'maintenance')
                ->assertJsonPath('message', 'Sedang diperbarui.')
                ->assertHeader('Retry-After');
            $this->getJson('/api/v1/activities', $headers)->assertStatus(503)->assertJsonPath('code', 'maintenance');
            $this->putJson('/api/v1/me/avatar', ['avatar_choice' => 'warga-1'], $headers)->assertStatus(503);
        }

        $this->getJson('/api/v1/notifications', $admin)->assertOk();
        $this->getJson('/api/v1/activities', $admin)->assertOk();
        $this->getJson('/api/v1/admin/settings', $admin)->assertOk()->assertJsonPath('data.maintenance.enabled', true);
    }

    public function test_session_check_and_logout_still_work_during_maintenance(): void
    {
        ['rw' => $rw, 'rt1' => $rt] = $this->unitTree();
        $admin = $this->bearerFor($this->userWithRole($rw, Role::SUPER_ADMIN));
        $warga = $this->bearerFor($this->userWithRole($rt, Role::WARGA));
        $this->enableMaintenance('Selesai pukul 22.00.');

        $this->getJson('/api/v1/me', $warga)
            ->assertOk()
            ->assertJsonPath('data.memberships.0.role', Role::WARGA)
            ->assertJsonPath('meta.maintenance', true)
            ->assertJsonPath('meta.maintenance_message', 'Selesai pukul 22.00.');
        $this->getJson('/api/v1/me', $admin)->assertOk()->assertJsonPath('meta.maintenance', true);
        $this->postJson('/api/v1/auth/logout', [], $warga)->assertOk();
    }

    public function test_me_reports_maintenance_off_by_default(): void
    {
        $this->getJson('/api/v1/me', $this->bearerFor(User::factory()->create()))->assertOk()->assertJsonPath('meta.maintenance', false);
    }

    public function test_expired_super_admin_membership_does_not_bypass_maintenance(): void
    {
        ['rw' => $rw] = $this->unitTree();
        $user = User::factory()->create();
        $this->grant($user, $rw, Role::SUPER_ADMIN)->update(['expires_at' => now()->subDay()]);
        $this->enableMaintenance();

        $this->getJson('/api/v1/notifications', $this->bearerFor($user))->assertStatus(503);
    }

    private function enableMaintenance(?string $message = null): void
    {
        AppSetting::query()->create(['key' => AppSetting::MAINTENANCE, 'value' => ['enabled' => true, 'message' => $message]]);
    }
}
