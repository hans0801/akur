<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\SessionManager;
use Illuminate\Http\Request;
use Tests\TestCase;

class SessionTest extends TestCase
{
    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer not-a-real-token'])->assertUnauthorized();
    }

    public function test_pending_user_can_read_me_but_not_admin_endpoints(): void
    {
        $headers = $this->bearerFor(User::factory()->pending()->create());

        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('data.account_status', 'pending');
        $this->getJson('/api/v1/admin/pending-users', $headers)
            ->assertForbidden()
            ->assertJsonPath('code', 'account_not_active');
    }

    public function test_me_lists_rts_below_an_rw_membership_first_and_labelled(): void
    {
        ['rw' => $rw, 'rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $user = $this->userWithRole($rw, Role::SUPER_ADMIN);

        $memberships = collect($this->getJson('/api/v1/me', $this->bearerFor($user))->assertOk()->json('data.memberships'));
        $this->assertEquals(
            [$rt1->id => true, $rt2->id => true, $rw->id => false],
            $memberships->pluck('inherited', 'unit.id')->all(),
        );
        // RT didahulukan, RW terakhir.
        $this->assertSame(['rt', 'rt', 'rw'], $memberships->pluck('unit.unit_type')->all());
        $this->assertSame("{$rt1->name} / {$rw->name}", $memberships->firstWhere('unit.id', $rt1->id)['unit']['name']);
    }

    public function test_logout_revokes_the_session(): void
    {
        $headers = $this->bearerFor(User::factory()->create());

        $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();

        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    }

    public function test_expired_session_is_rejected(): void
    {
        $user = User::factory()->create();
        $headers = $this->bearerFor($user);
        UserSession::query()->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    }

    public function test_suspended_user_session_stops_working(): void
    {
        $user = User::factory()->create();
        $headers = $this->bearerFor($user);
        $user->update(['account_status' => User::STATUS_SUSPENDED]);

        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    }

    public function test_user_cannot_revoke_another_users_session(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->bearerFor($bob);
        $bobSession = UserSession::query()->where('user_id', $bob->id)->sole();

        $this->deleteJson("/api/v1/auth/sessions/{$bobSession->id}", [], $this->bearerFor($alice))->assertNotFound();
        $this->assertNull($bobSession->fresh()->revoked_at);
    }

    public function test_revoke_other_sessions_keeps_current(): void
    {
        $user = User::factory()->create();
        $this->bearerFor($user);
        $this->bearerFor($user);
        $headers = $this->bearerFor($user);

        $this->postJson('/api/v1/auth/sessions/revoke-others', [], $headers)->assertOk()->assertJsonPath('revoked', 2);
        $this->assertSame(1, UserSession::query()->whereNull('revoked_at')->count());
    }

    public function test_cookie_session_authenticates_web_requests(): void
    {
        $user = User::factory()->create();
        $token = app(SessionManager::class)->issue($user, 'web', Request::create('/'))['token'];

        $this->withCredentials()->withUnencryptedCookie('akur_session', $token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_cookie_session_mutation_requires_csrf_header(): void
    {
        $user = User::factory()->create();
        $token = app(SessionManager::class)->issue($user, 'web', Request::create('/'))['token'];

        // Tanpa Origin pun ditolak karena cookie sesi terbawa.
        $this->withCredentials()->withUnencryptedCookie('akur_session', $token)->postJson('/api/v1/auth/logout')->assertForbidden();

        $this->withCredentials()->withUnencryptedCookie('akur_session', $token)
            ->withHeaders(['Origin' => 'https://app.test', 'X-AKUR-CSRF' => '1'])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }
}
