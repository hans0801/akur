<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeUnit;
use App\Models\User;
use App\Services\Access\UnitAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Dasar W6 (pengumuman & kegiatan). Baca: semua anggota unit yang terlihat (UnitAccess::visibleUnitIds).
 * Tulis: akur.community_write_roles pada unit konten (RW mencakup RT di bawahnya).
 */
abstract class CommunityController extends Controller
{
    public function __construct(
        protected readonly UnitAccess $access,
        protected readonly AuditLogger $audit,
        protected readonly Notifier $notifier,
    ) {}

    protected function authorizeWrite(Request $request, AdministrativeUnit $unit): void
    {
        $this->access->authorize($request->user(), $unit, config('akur.community_write_roles'));
    }

    protected function canWrite(User $user, AdministrativeUnit $unit): bool
    {
        return $this->access->hasAnyRole($user, $unit, config('akur.community_write_roles'));
    }

    /** @throws AuthorizationException */
    protected function ensureVisible(Request $request, string $unitId): void
    {
        if (! in_array($unitId, $this->access->visibleUnitIds($request->user()), true)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke unit ini.');
        }
    }

    /**
     * Notifikasi in-app ke semua anggota unit kecuali pelaku. Isi hanya judul konten, tanpa PII.
     *
     * @param  array<string, scalar|null>  $payload
     */
    protected function notifyMembers(AdministrativeUnit $unit, User $actor, string $type, string $title, string $body, array $payload): void
    {
        foreach ($this->access->memberUserIds($unit) as $userId) {
            if ($userId !== $actor->id) {
                $this->notifier->send($userId, $type, $title, $body, $payload + ['unit_id' => $unit->id]);
            }
        }
    }

    /** @return array{id: string, name: string, unit_type: string} */
    protected static function unitSummary(AdministrativeUnit $unit): array
    {
        return ['id' => $unit->id, 'name' => $unit->name, 'unit_type' => $unit->unit_type];
    }
}
