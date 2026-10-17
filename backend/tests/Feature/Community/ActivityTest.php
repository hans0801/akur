<?php

namespace Tests\Feature\Community;

use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    public function test_ketua_rt_creates_activity_and_members_are_notified(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $warga = $this->userWithRole($rt, Role::WARGA);

        $id = $this->postJson("/api/v1/units/{$rt->id}/activities", [
            'title' => 'Kerja bakti',
            'description' => 'Bersih-bersih selokan.',
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(2)->addHours(3)->toIso8601String(),
            'location_text' => 'Pos ronda',
            'category' => 'kerja_bakti',
        ], $this->bearerFor($ketua))
            ->assertCreated()
            ->assertJsonPath('data.status', 'planned')
            ->json('data.id');

        $this->assertTrue(AuditLog::query()->where('action', 'activity.created')->where('entity_id', $id)->exists());
        $this->assertTrue(AppNotification::query()->where('user_id', $warga->id)->where('type', 'activity.created')->exists());

        $this->getJson('/api/v1/activities', $this->bearerFor($warga))->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_warga_rsvps_with_linked_resident_and_sees_only_counts(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $warga = $this->userWithRole($rt, Role::WARGA);
        $resident = $this->residentIn($rt, ['user_id' => $warga->id, 'full_name' => 'Siti Warga']);
        $activity = $this->activity($rt, $ketua);
        $headers = $this->bearerFor($warga);

        $this->putJson("/api/v1/activities/{$activity->id}/participation", ['status' => 'maybe'], $headers)->assertOk();
        $this->putJson("/api/v1/activities/{$activity->id}/participation", ['status' => 'going'], $headers)->assertOk();

        $participant = ActivityParticipant::query()->sole();
        $this->assertSame([$resident->id, 'going'], [$participant->resident_id, $participant->participation_status]);

        $this->getJson("/api/v1/activities/{$activity->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.my_status', 'going')
            ->assertJsonPath('data.going_count', 1)
            ->assertJsonMissingPath('data.participants');

        $this->getJson("/api/v1/activities/{$activity->id}", $this->bearerFor($ketua))
            ->assertOk()
            ->assertJsonPath('data.participants.0.name', 'Siti Warga');
    }

    public function test_cross_rt_access_is_forbidden(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $activity = $this->activity($rt2, $this->userWithRole($rt2, Role::KETUA_RT));
        $ketua1 = $this->bearerFor($this->userWithRole($rt1, Role::KETUA_RT));
        $warga1 = $this->bearerFor($this->userWithRole($rt1, Role::WARGA));

        $this->getJson("/api/v1/activities/{$activity->id}", $warga1)->assertForbidden();
        $this->putJson("/api/v1/activities/{$activity->id}/participation", ['status' => 'going'], $warga1)->assertForbidden();
        $this->patchJson("/api/v1/activities/{$activity->id}", ['status' => 'cancelled'], $ketua1)->assertForbidden();
        $this->postJson("/api/v1/units/{$rt2->id}/activities", $this->payload(), $ketua1)->assertForbidden();
        $this->getJson('/api/v1/activities', $warga1)->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame('planned', $activity->fresh()->status);
        $this->assertSame(0, ActivityParticipant::query()->count());
    }

    public function test_bendahara_and_warga_cannot_manage(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $activity = $this->activity($rt, $this->userWithRole($rt, Role::KETUA_RT));
        $participant = ActivityParticipant::query()->create([
            'activity_id' => $activity->id, 'user_id' => User::factory()->create()->id, 'participation_status' => 'going',
        ]);

        foreach ([Role::BENDAHARA, Role::WARGA] as $role) {
            $headers = $this->bearerFor($this->userWithRole($rt, $role));
            $this->postJson("/api/v1/units/{$rt->id}/activities", $this->payload(), $headers)->assertForbidden();
            $this->patchJson("/api/v1/activities/{$activity->id}", ['title' => 'X'], $headers)->assertForbidden();
            $this->postJson("/api/v1/activities/{$activity->id}/participants/{$participant->id}/check-in", [], $headers)->assertForbidden();
        }
    }

    public function test_cancel_notifies_and_blocks_rsvp(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::SEKRETARIS);
        $warga = $this->userWithRole($rt, Role::WARGA);
        $activity = $this->activity($rt, $ketua);

        $this->patchJson("/api/v1/activities/{$activity->id}", ['status' => 'cancelled', 'reason' => 'Hujan'], $this->bearerFor($ketua))->assertOk();

        $this->assertTrue(AppNotification::query()->where('user_id', $warga->id)->where('type', 'activity.cancelled')->exists());
        $this->putJson("/api/v1/activities/{$activity->id}/participation", ['status' => 'going'], $this->bearerFor($warga))->assertConflict();
        $this->patchJson("/api/v1/activities/{$activity->id}", ['status' => 'planned'], $this->bearerFor($ketua))->assertConflict();
    }

    public function test_past_activity_rejects_rsvp_and_shows_under_past(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $warga = $this->bearerFor($this->userWithRole($rt, Role::WARGA));
        $activity = $this->activity($rt, $this->userWithRole($rt, Role::KETUA_RT), ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);

        $this->putJson("/api/v1/activities/{$activity->id}/participation", ['status' => 'going'], $warga)->assertConflict();
        $this->getJson('/api/v1/activities', $warga)->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/activities?when=past', $warga)->assertJsonPath('data.0.id', $activity->id);
    }

    public function test_manager_checks_in_participant_of_that_activity_only(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $headers = $this->bearerFor($ketua);
        $a = $this->activity($rt, $ketua);
        $b = $this->activity($rt, $ketua);
        $p = ActivityParticipant::query()->create(['activity_id' => $b->id, 'user_id' => $ketua->id, 'participation_status' => 'going']);

        $this->postJson("/api/v1/activities/{$a->id}/participants/{$p->id}/check-in", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/activities/{$b->id}/participants/{$p->id}/check-in", [], $headers)->assertOk();
        $this->assertNotNull($p->fresh()->checked_in_at);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'title' => 'Rapat warga',
            'description' => 'Bahas iuran.',
            'starts_at' => now()->addDay()->toIso8601String(),
            'location_text' => 'Balai warga',
            'category' => 'rapat',
        ];
    }

    private function activity($unit, User $organizer, array $attributes = []): Activity
    {
        return Activity::query()->create($attributes + [
            'unit_id' => $unit->id,
            'title' => 'Kegiatan',
            'description' => 'Deskripsi',
            'starts_at' => now()->addDays(3),
            'location_text' => 'Pos ronda',
            'category' => 'sosial',
            'organizer_user_id' => $organizer->id,
            'status' => Activity::STATUS_PLANNED,
        ]);
    }
}
