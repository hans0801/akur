<?php

namespace App\Http\Resources\Population;

use App\Models\ResidentMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ResidentMovement */
class ResidentMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resident_id' => $this->resident_id,
            'movement_type' => $this->movement_type,
            'from_household_id' => $this->from_household_id,
            'to_household_id' => $this->to_household_id,
            'effective_date' => $this->effective_date?->toDateString(),
            'reason' => $this->reason,
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->display_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
