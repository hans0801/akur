<?php

namespace Tests\Feature\Community;

use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    public function test_ketua_rt_publishes_and_members_are_notified(): void
    {
        ['rt1' => $rt, 'rt2' => $rt2] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $warga = $this->userWithRole($rt, Role::WARGA);
        $outsider = $this->userWithRole($rt2, Role::WARGA);

        $id = $this->postJson("/api/v1/units/{$rt->id}/announcements", [
            'title' => 'Kerja bakti Minggu',
            'body' => 'Kumpul jam 7 di pos ronda.',
            'status' => 'published',
        ], $this->bearerFor($ketua))
            ->assertCreated()
            ->assertJsonPath('data.is_live', true)
            ->json('data.id');

        $this->assertTrue(AuditLog::query()->where('action', 'announcement.created')->where('entity_id', $id)->exists());
        $this->assertSame(1, AppNotification::query()->where('user_id', $warga->id)->where('type', 'announcement.published')->count());
        $this->assertSame(0, AppNotification::query()->whereIn('user_id', [$ketua->id, $outsider->id])->count());

        $this->getJson('/api/v1/announcements', $this->bearerFor($warga))
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.can_edit', false);
    }

    public function test_drafts_and_scheduled_posts_are_hidden_from_warga(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::KETUA_RT);
        $warga = $this->bearerFor($this->userWithRole($rt, Role::WARGA));
        $draft = $this->announcement($rt, $ketua, ['status' => 'draft']);
        $scheduled = $this->announcement($rt, $ketua, ['publish_at' => now()->addDay()]);
        $expired = $this->announcement($rt, $ketua, ['publish_at' => now()->subDays(3), 'expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/announcements', $warga)->assertOk()->assertJsonCount(0, 'data');
        foreach ([$draft, $scheduled, $expired] as $a) {
            $this->getJson("/api/v1/announcements/{$a->id}", $warga)->assertForbidden();
        }

        $this->getJson("/api/v1/units/{$rt->id}/announcements", $this->bearerFor($ketua))->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/units/{$rt->id}/announcements", $warga)->assertForbidden();
    }

    public function test_rt_member_sees_rw_announcements_but_not_other_rts(): void
    {
        ['rw' => $rw, 'rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $ketuaRw = $this->userWithRole($rw, Role::KETUA_RW);
        $warga = $this->bearerFor($this->userWithRole($rt1, Role::WARGA));
        $rwPost = $this->announcement($rw, $ketuaRw);
        $otherRt = $this->announcement($rt2, $ketuaRw);

        $this->getJson('/api/v1/announcements', $warga)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rwPost->id);
        $this->getJson("/api/v1/announcements/{$otherRt->id}", $warga)->assertForbidden();
        $this->getJson("/api/v1/announcements?unit_id={$rt2->id}", $warga)->assertForbidden();

        // Ketua RW melihat semua RT di bawahnya.
        $this->getJson('/api/v1/announcements', $this->bearerFor($ketuaRw))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_only_managers_of_that_unit_can_write(): void
    {
        ['rt1' => $rt1, 'rt2' => $rt2] = $this->unitTree();
        $ketua2 = $this->userWithRole($rt2, Role::KETUA_RT);
        $post = $this->announcement($rt1, $this->userWithRole($rt1, Role::KETUA_RT));

        foreach ([[$rt1, Role::BENDAHARA], [$rt1, Role::WARGA]] as [$unit, $role]) {
            $headers = $this->bearerFor($this->userWithRole($unit, $role));
            $this->postJson("/api/v1/units/{$rt1->id}/announcements", ['title' => 'X', 'body' => 'Y'], $headers)->assertForbidden();
            $this->patchJson("/api/v1/announcements/{$post->id}", ['title' => 'Dibajak'], $headers)->assertForbidden();
        }

        $headers = $this->bearerFor($ketua2);
        $this->postJson("/api/v1/units/{$rt1->id}/announcements", ['title' => 'X', 'body' => 'Y'], $headers)->assertForbidden();
        $this->patchJson("/api/v1/announcements/{$post->id}", ['status' => 'archived'], $headers)->assertForbidden();

        $this->assertSame('published', $post->fresh()->status);
    }

    public function test_publishing_a_draft_notifies_once_and_archiving_hides_it(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $ketua = $this->userWithRole($rt, Role::SEKRETARIS);
        $warga = $this->userWithRole($rt, Role::WARGA);
        $headers = $this->bearerFor($ketua);
        $draft = $this->announcement($rt, $ketua, ['status' => 'draft']);

        $this->patchJson("/api/v1/announcements/{$draft->id}", ['status' => 'published'], $headers)->assertOk()->assertJsonPath('data.is_live', true);
        $this->patchJson("/api/v1/announcements/{$draft->id}", ['title' => 'Judul diperbaiki'], $headers)->assertOk();
        $this->assertSame(1, AppNotification::query()->where('user_id', $warga->id)->count());

        $this->patchJson("/api/v1/announcements/{$draft->id}", ['status' => 'archived'], $headers)->assertOk();
        $this->getJson('/api/v1/announcements', $this->bearerFor($warga))->assertJsonCount(0, 'data');

        $statusChanges = AuditLog::query()->where('action', 'announcement.updated')->get()
            ->map(fn ($log) => $log->metadata['changes']['status'] ?? null)->filter()->values()->all();
        $this->assertEqualsCanonicalizing([['draft', 'published'], ['published', 'archived']], $statusChanges);
    }

    public function test_expiry_must_be_after_publish_time(): void
    {
        ['rt1' => $rt] = $this->unitTree();
        $headers = $this->bearerFor($this->userWithRole($rt, Role::KETUA_RT));

        $this->postJson("/api/v1/units/{$rt->id}/announcements", [
            'title' => 'X', 'body' => 'Y', 'publish_at' => now()->addDays(3)->toIso8601String(), 'expires_at' => now()->addDay()->toIso8601String(),
        ], $headers)->assertJsonValidationErrors('expires_at');

        $this->postJson("/api/v1/units/{$rt->id}/announcements", [
            'title' => 'X', 'body' => 'Y', 'expires_at' => now()->addDay()->toIso8601String(),
        ], $headers)->assertCreated();
    }

    public function test_pending_account_cannot_read_feed(): void
    {
        $this->getJson('/api/v1/announcements', $this->bearerFor(User::factory()->pending()->create()))->assertForbidden();
    }

    private function announcement($unit, User $author, array $attributes = []): Announcement
    {
        return Announcement::query()->create($attributes + [
            'unit_id' => $unit->id,
            'title' => 'Pengumuman',
            'body' => 'Isi',
            'status' => 'published',
            'author_user_id' => $author->id,
        ]);
    }
}
