<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMembership;
use App\Services\Access\UnitAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionManager;
use App\Services\Notifications\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-02: pengurus meninjau akun pending dan menautkannya ke data warga yang benar.
 */
class PendingUserController extends Controller
{
    public function __construct(
        private readonly UnitAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureCanReview($request->user());

        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->where('account_status', User::STATUS_PENDING)
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $w->where('display_name', 'ilike', $like)->orWhere('email', 'ilike', $like);
            }))
            ->latest()
            ->paginate(20, ['id', 'display_name', 'email', 'avatar_url', 'created_at', 'last_login_at']);

        return response()->json($users);
    }

    public function link(Request $request, User $user, Notifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'resident_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $actor = $request->user();
        if ($actor->is($user)) {
            throw new AuthorizationException('Tidak dapat memverifikasi akun sendiri.');
        }

        $resident = Resident::query()->with('household.unit')->findOrFail($data['resident_id']);
        $unit = $resident->household?->unit;
        abort_if($unit === null, 422, 'Data warga belum terhubung ke keluarga/unit.');

        $this->access->authorize($actor, $unit, config('akur.linking_roles'));

        DB::transaction(function () use ($user, $resident, $unit, $actor, $data) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedResident = Resident::query()->lockForUpdate()->findOrFail($resident->id);

            abort_if($lockedUser->account_status !== User::STATUS_PENDING, 409, 'Akun ini tidak berstatus pending.');
            abort_if($lockedResident->user_id !== null, 409, 'Data warga ini sudah terhubung ke akun lain.');
            abort_if(! $lockedResident->is_alive, 422, 'Data warga berstatus meninggal.');

            $lockedResident->update(['user_id' => $lockedUser->id]);
            $lockedUser->update(['account_status' => User::STATUS_ACTIVE]);

            UserMembership::query()->updateOrCreate(
                ['user_id' => $lockedUser->id, 'unit_id' => $unit->id, 'role_id' => Role::idFor(Role::WARGA)],
                ['status' => UserMembership::STATUS_ACTIVE, 'granted_by' => $actor->id, 'granted_at' => now(), 'expires_at' => null],
            );

            $this->audit->log('user.linked_to_resident', 'user', $lockedUser->id, $unit->id, [
                'resident_id' => $lockedResident->id,
                'note' => $data['note'] ?? null,
            ]);
        });

        $notifier->send($user->id, 'account.verified', 'Akun terverifikasi', "Akun Anda telah diverifikasi sebagai warga {$unit->name}.", ['unit_id' => $unit->id]);

        return response()->json(['message' => 'Akun berhasil ditautkan ke data warga.']);
    }

    public function reject(Request $request, User $user, SessionManager $sessions): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $actor = $request->user();
        $this->ensureCanReview($actor);
        if ($actor->is($user)) {
            throw new AuthorizationException('Tidak dapat menolak akun sendiri.');
        }

        abort_if($user->account_status !== User::STATUS_PENDING, 409, 'Akun ini tidak berstatus pending.');

        DB::transaction(function () use ($user, $sessions, $data) {
            $user->update(['account_status' => User::STATUS_DISABLED]);
            $sessions->revokeAllFor($user);
            $this->audit->log('user.pending_rejected', 'user', $user->id, metadata: ['reason' => $data['reason']]);
        });

        return response()->json(['message' => 'Akun ditolak.']);
    }

    private function ensureCanReview(User $actor): void
    {
        if ($this->access->unitIdsWhere($actor, config('akur.linking_roles')) === []) {
            throw new AuthorizationException('Anda tidak berwenang meninjau akun.');
        }
    }
}
