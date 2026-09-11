<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $orgId = $this->faker->unique()->numberBetween(1_000_000, 99_999_999);

        return [
            'url' => "https://yandex.ru/maps/org/company_{$orgId}/{$orgId}/",
            'normalized_url' => "https://yandex.ru/maps/org/company_{$orgId}/{$orgId}/",
            'yandex_id' => (string) $orgId,
            'name' => $this->faker->company(),
            'rating' => $this->faker->randomFloat(2, 1, 5),
            'reviews_count' => 0,
            'ratings_count' => $this->faker->numberBetween(0, 500),
            'parse_status' => 'idle',
            'last_parsed_at' => null,
            'last_error' => null,
        ];
    }
}
