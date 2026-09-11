<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ParsingLog>
 */
class ParsingLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'status' => 'completed',
            'reviews_collected' => $this->faker->numberBetween(0, 600),
            'error_type' => null,
            'error_message' => null,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ];
    }
}
