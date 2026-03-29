<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id'  => 0,
            'name'       => $this->faker->word(),
            'icon'       => $this->faker->imageUrl(100, 100),
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_enabled' => 1,
            'level'      => 1,
        ];
    }
}
