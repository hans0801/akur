<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMembership;
use App\Services\Access\UnitAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * FR-03: penetapan/pencabutan peran per unit, sesuai matriks akur.grantable_roles. Semua tercatat di audit log.
 */
class MembershipController extends Controller
{
    public function __construct(
        private readonly UnitAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->access->authorize($request->user(), $unit, config('akur.linking_roles'));

        $memberships = UserMembership::query()
            ->effective()
            ->where('unit_id', $unit->id)
            ->with(['user:id,display_name,email,avatar_url', 'role:id,code,name'])
            ->orderBy('role_id')
            ->paginate(50);

        return response()->json($memberships);
    }

    public function store(Request $request, Notifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'unit_id' => ['required', 'uuid'],
            'role' => ['required', 'string', Rule::exists('roles', 'code')],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $actor = $request->user();
        $unit = AdministrativeUnit::query()->findOrFail($data['unit_id']);
        $target = User::query()->findOrFail($data['user_id']);

        $this->ensureCanManage($actor, $target, $unit, $data['role']);
        abort_if($target->account_status !== User::STATUS_ACTIVE, 422, 'Akun penerima harus aktif (sudah diverifikasi).');

        $membership = DB::transaction(function () use ($data, $actor, $unit, $target) {
            $membership = UserMembership::query()
                ->where(['user_id' => $target->id, 'unit_id' => $unit->id, 'role_id' => Role::idFor($data['role'])])
                ->lockForUpdate()
                ->first();

            abort_if(
                $membership?->status === UserMembership::STATUS_ACTIVE && ($membership->expires_at === null || $membership->expires_at->isFuture()),
                409,
                'Peran ini sudah aktif.',
            );

            $membership ??= new UserMembership(['user_id' => $target->id, 'unit_id' => $unit->id, 'role_id' => Role::idFor($data['role'])]);
            $membership->fill([
                'status' => UserMembership::STATUS_ACTIVE,
                'granted_by' => $actor->id,
                'granted_at' => now(),
                'expires_at' => $data['expires_at'] ?? null,
            ])->save();

            $this->audit->log('membership.granted', 'user_membership', $membership->id, $unit->id, [
                'user_id' => $target->id,
                'role' => $data['role'],
                'expires_at' => $data['expires_at'] ?? null,
                'reason' => $data['reason'],
            ]);

            return $membership;
        });

        $roleName = Role::query()->where('code', $data['role'])->value('name');
        $notifier->send($target->id, 'membership.granted', 'Peran baru', "Anda ditetapkan sebagai {$roleName} di {$unit->label()}.", ['unit_id' => $unit->id]);

        return response()->json(['data' => ['id' => $membership->id]], 201);
    }

    public function destroy(Request $request, UserMembership $membership): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $membership->load(['unit', 'role', 'user']);
        $this->ensureCanManage($request->user(), $membership->user, $membership->unit, $membership->role->code);

        abort_if($membership->status === UserMembership::STATUS_REVOKED, 409, 'Peran ini sudah dicabut.');

        DB::transaction(function () use ($membership, $data) {
            $membership->update(['status' => UserMembership::STATUS_REVOKED]);
            $this->audit->log('membership.revoked', 'user_membership', $membership->id, $membership->unit_id, [
                'user_id' => $membership->user_id,
                'role' => $membership->role->code,
                'reason' => $data['reason'],
            ]);
        });

        return response()->json(['message' => 'Peran dicabut.']);
    }

    private function ensureCanManage(User $actor, User $target, AdministrativeUnit $unit, string $role): void
    {
        // Mencegah eskalasi: tidak ada yang boleh mengubah perannya sendiri.
        if ($actor->is($target)) {
            throw new AuthorizationException('Tidak dapat mengubah peran sendiri.');
        }

        if (! in_array($role, $this->access->grantableRolesOn($actor, $unit), true)) {
            throw new AuthorizationException('Anda tidak berwenang mengelola peran ini di unit tersebut.');
        }
    }
}
