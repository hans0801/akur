<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    public function test_user_picks_a_preset_avatar_and_it_shows_on_me(): void
    {
        $user = User::factory()->create();
        $headers = $this->bearerFor($user);

        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('data.avatar_choice', null);

        $this->putJson('/api/v1/me/avatar', ['avatar_choice' => 'warga-3'], $headers)
            ->assertOk()
            ->assertJsonPath('data.avatar_choice', 'warga-3');
        $this->assertSame('warga-3', $user->fresh()->avatar_choice);
        $this->getJson('/api/v1/me', $headers)->assertJsonPath('data.avatar_choice', 'warga-3');
        $this->assertTrue(AuditLog::query()->where('action', 'profile.avatar_changed')->where('entity_id', $user->id)->exists());

        // null = kembali ke avatar bawaan.
        $this->putJson('/api/v1/me/avatar', ['avatar_choice' => null], $headers)->assertOk()->assertJsonPath('data.avatar_choice', null);
    }

    public function test_pending_user_can_change_avatar(): void
    {
        $user = User::factory()->pending()->create();

        $this->putJson('/api/v1/me/avatar', ['avatar_choice' => 'initials'], $this->bearerFor($user))->assertOk();
        $this->assertSame('initials', $user->fresh()->avatar_choice);
    }

    public function test_unknown_choice_or_missing_field_is_rejected(): void
    {
        $headers = $this->bearerFor(User::factory()->create());

        $this->putJson('/api/v1/me/avatar', ['avatar_choice' => 'https://evil.example/x.png'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar_choice');
        $this->putJson('/api/v1/me/avatar', [], $headers)->assertUnprocessable()->assertJsonValidationErrors('avatar_choice');
        $this->putJson('/api/v1/me/avatar', ['avatar_choice' => 'warga-1'])->assertUnauthorized();
    }

    public function test_google_photo_requires_a_google_picture(): void
    {
        $withPhoto = User::factory()->create(['avatar_url' => 'https://lh3.googleusercontent.com/a/photo']);
        $withoutPhoto = User::factory()->create(['avatar_url' => null]);

        $this->putJson('/api/v1/me/avatar', ['avatar_choice' => 'google'], $this->bearerFor($withPhoto))->assertOk();
        $this->putJson('/api/v1/me/avatar', ['avatar_choice' => 'google'], $this->bearerFor($withoutPhoto))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar_choice');
    }
}
