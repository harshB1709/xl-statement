<?php

namespace Database\Factories;

use App\Enums\AmountStyle;
use App\Enums\TargetField;
use App\Models\MappingProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MappingProfile>
 */
class MappingProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fingerprint' => fake()->unique()->sha1(),
            'name' => fake()->words(2, true),
            'mapping' => [
                'targets' => [
                    '0' => TargetField::Date->value,
                    '1' => TargetField::Description->value,
                    '2' => TargetField::Debit->value,
                    '3' => TargetField::Credit->value,
                    '4' => TargetField::Balance->value,
                ],
                'date_format' => 'd/m/Y',
                'amount_style' => AmountStyle::SeparateDrCr->value,
                'profile_name' => null,
            ],
            'times_used' => 0,
            'last_used_at' => null,
        ];
    }
}
