<?php

namespace App\Services\Access;

use App\Models\AdministrativeUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMembership;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Otorisasi berbasis peran + cakupan unit, selalu di server.
 * Keanggotaan di RW berlaku juga untuk semua RT di bawahnya.
 */
class UnitAccess
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    /**
     * Kode peran efektif pengguna pada unit (dari keanggotaan di unit itu atau RW induknya).
     *
     * @return list<string>
     */
    public function rolesOn(User $user, AdministrativeUnit $unit): array
    {
        $key = $user->id.'|'.$unit->id;

        return $this->cache[$key] ??= UserMembership::query()
            ->effective()
            ->where('user_id', $user->id)
            ->whereIn('unit_id', $unit->selfAndAncestorIds())
            ->join('roles', 'roles.id', '=', 'user_memberships.role_id')
            ->distinct()
            ->pluck('roles.code')
            ->all();
    }

    /** @param  list<string>  $roles */
    public function hasAnyRole(User $user, AdministrativeUnit $unit, array $roles): bool
    {
        return $user->account_status === User::STATUS_ACTIVE
            && array_intersect($this->rolesOn($user, $unit), $roles) !== [];
    }

    /**
     * @param  list<string>  $roles
     *
     * @throws AuthorizationException
     */
    public function authorize(User $user, AdministrativeUnit $unit, array $roles): void
    {
        if (! $this->hasAnyRole($user, $unit, $roles)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke unit ini.');
        }
    }

    /**
     * Semua ID unit (termasuk RT turunan) tempat pengguna memegang salah satu peran.
     *
     * @param  list<string>  $roles
     * @return list<string>
     */
    public function unitIdsWhere(User $user, array $roles): array
    {
        if ($user->account_status !== User::STATUS_ACTIVE) {
            return [];
        }

        $direct = UserMembership::query()
            ->effective()
            ->where('user_id', $user->id)
            ->whereHas('role', fn ($q) => $q->whereIn('code', $roles))
            ->pluck('unit_id')
            ->all();

        if ($direct === []) {
            return [];
        }

        $children = AdministrativeUnit::query()->whereIn('parent_unit_id', $direct)->pluck('id')->all();

        return array_values(array_unique([...$direct, ...$children]));
    }

    /**
     * Unit yang kontennya (pengumuman/kegiatan) boleh dibaca pengguna dengan peran apa pun:
     * unit keanggotaannya, RW induknya, dan — untuk keanggotaan RW — semua RT di bawahnya.
     *
     * @return list<string>
     */
    public function visibleUnitIds(User $user): array
    {
        if ($user->account_status !== User::STATUS_ACTIVE) {
            return [];
        }

        $direct = UserMembership::query()->effective()->where('user_id', $user->id)->pluck('unit_id')->unique()->all();
        if ($direct === []) {
            return [];
        }

        $units = AdministrativeUnit::query()->whereIn('id', $direct)->get(['id', 'parent_unit_id']);
        $children = AdministrativeUnit::query()->whereIn('parent_unit_id', $direct)->pluck('id')->all();

        return array_values(array_unique(array_filter([
            ...$direct,
            ...$units->pluck('parent_unit_id')->all(),
            ...$children,
        ])));
    }

    /**
     * Pengguna aktif yang menjadi anggota unit (untuk RW: termasuk anggota RT di bawahnya). Penerima notifikasi.
     *
     * @return list<string>
     */
    public function memberUserIds(AdministrativeUnit $unit): array
    {
        return UserMembership::query()
            ->effective()
            ->whereIn('unit_id', $unit->selfAndDescendantIds())
            ->whereHas('user', fn ($q) => $q->where('account_status', User::STATUS_ACTIVE))
            ->distinct()
            ->pluck('user_id')
            ->all();
    }

    /**
     * Peran yang boleh diberikan pengguna pada unit tertentu (matriks config akur.grantable_roles).
     *
     * @return list<string>
     */
    public function grantableRolesOn(User $user, AdministrativeUnit $unit): array
    {
        if ($user->account_status !== User::STATUS_ACTIVE) {
            return [];
        }

        $matrix = config('akur.grantable_roles');
        $grantable = [];
        foreach ($this->rolesOn($user, $unit) as $role) {
            $grantable = [...$grantable, ...($matrix[$role] ?? [])];
        }

        // Ketua RW tidak bisa memberi peran di level RW kecuali warga — penetapan pengurus RW oleh super admin.
        if ($unit->unit_type === AdministrativeUnit::TYPE_RW && ! in_array(Role::SUPER_ADMIN, $this->rolesOn($user, $unit), true)) {
            $grantable = array_intersect($grantable, [Role::WARGA]);
        }

        return array_values(array_unique($grantable));
    }
}
