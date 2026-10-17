<?php

namespace App\Http\Resources\Population;

use App\Models\Resident;
use App\Services\Pii\PopulationPii;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Data warga dengan NIK yang selalu dimasking. NIK utuh hanya lewat endpoint reveal yang diaudit.
 *
 * @mixin Resident
 */
class ResidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ciphertext = $this->resource->getAttributes()['nik_ciphertext'] ?? null;

        return [
            'id' => $this->id,
            'household_id' => $this->household_id,
            'unit_id' => $this->household?->unit_id,
            'address_text' => $this->household?->address_text,
            'full_name' => $this->full_name,
            'has_nik' => $ciphertext !== null,
            'nik_masked' => app(PopulationPii::class)->masked($ciphertext, PopulationPii::NIK),
            'birth_place' => $this->birth_place,
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'marital_status' => $this->marital_status,
            'occupation' => $this->occupation,
            'education' => $this->education,
            'is_household_head' => $this->is_household_head,
            'is_alive' => $this->is_alive,
            'residency_status' => $this->residency_status,
            'moved_in_at' => $this->moved_in_at?->toDateString(),
            'moved_out_at' => $this->moved_out_at?->toDateString(),
            'is_linked' => $this->user_id !== null,
            'movements' => ResidentMovementResource::collection($this->whenLoaded('movements')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
