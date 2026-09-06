<?php

namespace Database\Factories;

use App\Models\Conversion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversion>
 */
class ConversionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'output_path' => storage_path('app/'.fake()->uuid().'.xlsx'),
            'file_count' => fake()->numberBetween(1, 5),
            'transaction_count' => fake()->numberBetween(1, 200),
            'status' => 'completed',
            'warnings' => [],
        ];
    }
}
