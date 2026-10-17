<?php

namespace App\Http\Resources;

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
        $this->resource->loadMissing(['memberships' => fn ($q) => $q->effective()->with(['unit', 'role']), 'resident.household']);

        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'account_status' => $this->account_status,
            'memberships' => $this->memberships->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role->code,
                'role_name' => $m->role->name,
                'unit' => [
                    'id' => $m->unit->id,
                    'unit_type' => $m->unit->unit_type,
                    'code' => $m->unit->code,
                    'name' => $m->unit->name,
                    'parent_unit_id' => $m->unit->parent_unit_id,
                ],
                'expires_at' => $m->expires_at?->toIso8601String(),
            ])->values(),
            'resident' => $this->resident ? [
                'id' => $this->resident->id,
                'full_name' => $this->resident->full_name,
                'household_id' => $this->resident->household_id,
                'unit_id' => $this->resident->household?->unit_id,
            ] : null,
        ];
    }
}
