<?php

namespace App\Http\Resources;

use App\Models\AdministrativeUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profil pengguna yang sedang login beserta peran per unit. Tanpa NIK/KK.
 *
 * @mixin User
 */
class CurrentUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['memberships' => fn ($q) => $q->effective()->with(['unit.parent', 'unit.children.parent', 'role']), 'resident.household']);

        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'avatar_choice' => $this->avatar_choice,
            'account_status' => $this->account_status,
            'memberships' => $this->membershipEntries(),
            'resident' => $this->resident ? [
                'id' => $this->resident->id,
                'full_name' => $this->resident->full_name,
                'household_id' => $this->resident->household_id,
                'unit_id' => $this->resident->household?->unit_id,
            ] : null,
        ];
    }

    /**
     * Keanggotaan langsung, ditambah turunan untuk setiap RT di bawah keanggotaan RW (`inherited: true`),
     * karena peran di RW berlaku juga di RT-nya (UnitAccess). Dengan begitu aplikasi bisa menawarkan RT
     * pada pilihan unit kas, iuran, warga, dan laporan. RT didahulukan; surat dan kas umumnya milik RT.
     *
     * @return list<array<string, mixed>>
     */
    private function membershipEntries(): array
    {
        $entry = fn ($m, AdministrativeUnit $unit, bool $inherited) => [
            'id' => $m->id,
            'role' => $m->role->code,
            'role_name' => $m->role->name,
            'unit' => [
                'id' => $unit->id,
                'unit_type' => $unit->unit_type,
                'code' => $unit->code,
                'name' => $unit->label(),
                'parent_unit_id' => $unit->parent_unit_id,
            ],
            'inherited' => $inherited,
            'expires_at' => $m->expires_at?->toIso8601String(),
        ];

        $entries = [];
        foreach ($this->memberships as $m) {
            $entries[$m->role->code.'|'.$m->unit_id] = $entry($m, $m->unit, false);
        }
        foreach ($this->memberships as $m) {
            foreach ($m->unit->unit_type === AdministrativeUnit::TYPE_RW ? $m->unit->children : [] as $child) {
                $entries[$m->role->code.'|'.$child->id] ??= $entry($m, $child, true);
            }
        }

        return collect($entries)
            ->sortBy(fn ($e) => [$e['unit']['unit_type'] === AdministrativeUnit::TYPE_RT ? 0 : 1, $e['unit']['name'], $e['inherited']])
            ->values()
            ->all();
    }
}
