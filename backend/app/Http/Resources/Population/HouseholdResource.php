<?php

namespace App\Http\Resources\Population;

use App\Models\Household;
use App\Services\Pii\PopulationPii;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Keluarga (KK) dengan No. KK yang selalu dimasking.
 *
 * @mixin Household
 */
class HouseholdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ciphertext = $this->resource->getAttributes()['kk_number_ciphertext'] ?? null;

        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'household_number' => $this->household_number,
            'has_kk' => $ciphertext !== null,
            'kk_masked' => app(PopulationPii::class)->masked($ciphertext, PopulationPii::KK),
            'head_resident_id' => $this->head_resident_id,
            'head_name' => $this->whenLoaded('head', fn () => $this->head?->full_name),
            'street_id' => $this->street_id,
            'street_name' => $this->whenLoaded('street', fn () => $this->street?->name),
            'house_id' => $this->house_id,
            'house_number' => $this->whenLoaded('house', fn () => $this->house?->house_number),
            'address_text' => $this->address_text,
            'status' => $this->status,
            'members_count' => $this->whenCounted('residents'),
            'members' => ResidentResource::collection($this->whenLoaded('residents')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
