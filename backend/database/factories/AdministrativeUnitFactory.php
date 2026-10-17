<?php

namespace Database\Factories;

use App\Models\AdministrativeUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdministrativeUnit>
 */
class AdministrativeUnitFactory extends Factory
{
    public function definition(): array
    {
        $code = str_pad((string) fake()->unique()->numberBetween(1, 99), 2, '0', STR_PAD_LEFT);

        return [
            'unit_type' => AdministrativeUnit::TYPE_RW,
            'code' => $code,
            'name' => "RW {$code}",
            'is_active' => true,
        ];
    }

    public function rtOf(AdministrativeUnit $rw): static
    {
        return $this->state(fn (array $attributes) => [
            'unit_type' => AdministrativeUnit::TYPE_RT,
            'parent_unit_id' => $rw->id,
            'name' => "RT {$attributes['code']}",
        ]);
    }
}
