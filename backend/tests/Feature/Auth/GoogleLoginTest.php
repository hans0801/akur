<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\AuthIdentity;
use App\Models\User;
use App\Models\UserSession;
use Tests\Concerns\FakesGoogleTokens;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use FakesGoogleTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGoogleJwks();
    }

    public function test_first_login_creates_pending_account_without_roles(): void
    {
        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->googleIdToken(),
            'platform' => 'android',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.account_status', 'pending')
            ->assertJsonPath('user.memberships', [])
            ->assertJsonPath('user.resident', null);
        $this->assertIsString($response->json('token'));

        $identity = AuthIdentity::query()->sole();
        $this->assertSame('1098765432101234567890', $identity->provider_subject);
        $this->assertSame(User::STATUS_PENDING, $identity->user->account_status);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.account_created')->exists());
    }

    public function test_session_token_is_stored_hashed_not_raw(): void
    {
        $token = $this->postJson('/api/v1/auth/google', ['id_token' => $this->googleIdToken(), 'platform' => 'android'])->json('token');

        $this->assertSame(1, UserSession::query()->whereRaw("refresh_token_hash = decode(?, 'hex')", [hash('sha256', $token)])->count());
        $this->assertSame(1, UserSession::query()->whereRaw('octet_length(refresh_token_hash) = 32')->count());
    }

    public function test_identity_is_keyed_on_sub_not_email(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->googleIdToken(), 'platform' => 'android'])->assertOk();
        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->googleIdToken(['email' => 'ganti@example.com']),
            'platform' => 'android',
        ])->assertOk();

        $this->assertSame(1, User::query()->count());
        $this->assertSame('ganti@example.com', User::query()->sole()->email);

        // Email sama tetapi sub berbeda = akun berbeda.
        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->googleIdToken(['sub' => 'other-sub', 'email' => 'ganti@example.com']),
            'platform' => 'android',
        ])->assertOk();
        $this->assertSame(2, User::query()->count());
    }

    public function test_rejects_wrong_audience(): void
    {
        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->googleIdToken(['aud' => 'someone-else.apps.googleusercontent.com']),
            'platform' => 'android',
        ])->assertUnauthorized();
        $this->assertSame(0, User::query()->count());
    }

    public function test_rejects_wrong_issuer(): void
    {
        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->googleIdToken(['iss' => 'https://evil.example.com']),
            'platform' => 'android',
        ])->assertUnauthorized();
    }

    public function test_rejects_expired_token(): void
    {
        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->googleIdToken(['iat' => time() - 7200, 'exp' => time() - 3600]),
            'platform' => 'android',
        ])->assertUnauthorized();
    }

    public function test_rejects_token_signed_by_unknown_key(): void
    {
        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->googleIdToken(signingKey: 'attacker-test-key'),
            'platform' => 'android',
        ])->assertUnauthorized();
    }

    public function test_disabled_account_cannot_sign_in(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->googleIdToken(), 'platform' => 'android'])->assertOk();
        User::query()->update(['account_status' => User::STATUS_DISABLED]);

        $this->postJson('/api/v1/auth/google', ['id_token' => $this->googleIdToken(), 'platform' => 'android'])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_blocked');
    }

    public function test_web_login_sets_httponly_cookie_and_hides_token(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://app.test', 'X-AKUR-CSRF' => '1'])
            ->postJson('/api/v1/auth/google', ['id_token' => $this->googleIdToken(), 'platform' => 'web']);

        $response->assertOk()->assertJsonMissingPath('token');

        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'akur_session');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/api', $cookie->getPath());
    }

    public function test_browser_request_without_csrf_header_is_rejected(): void
    {
        $this->withHeaders(['Origin' => 'https://app.test'])
            ->postJson('/api/v1/auth/google', ['id_token' => $this->googleIdToken(), 'platform' => 'web'])
            ->assertForbidden();
    }

    public function test_browser_request_from_unknown_origin_is_rejected(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.example.com', 'X-AKUR-CSRF' => '1'])
            ->postJson('/api/v1/auth/google', ['id_token' => $this->googleIdToken(), 'platform' => 'web'])
            ->assertForbidden();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/google', ['id_token' => 'x', 'platform' => 'android']);
        }

        $this->postJson('/api/v1/auth/google', ['id_token' => 'x', 'platform' => 'android'])->assertTooManyRequests();
    }
}
