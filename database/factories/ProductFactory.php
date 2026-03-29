<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id'          => \App\Models\Category::factory(),
            'shipping_template_id' => null,
            'title'                => $this->faker->catchPhrase(),
            'subtitle'             => $this->faker->sentence(),
            'main_image'           => $this->faker->imageUrl(400, 400),
            'images'               => [
                $this->faker->imageUrl(800, 800),
                $this->faker->imageUrl(800, 800),
            ],
            'description'          => $this->faker->paragraphs(3, true),
            'base_price'           => $this->faker->randomFloat(2, 50, 5000),
            'sales_count'          => $this->faker->numberBetween(0, 1000),
            'review_count'         => $this->faker->numberBetween(0, 500),
            'status'               => 1,
            'sort_order'           => 0,
        ];
    }
}
