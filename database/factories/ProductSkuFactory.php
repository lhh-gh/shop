<?php

namespace Database\Factories;

use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSku>
 */
class ProductSkuFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id'     => \App\Models\Product::factory(),
            'title'          => $this->faker->word(),
            'attributes'     => ['颜色' => $this->faker->colorName()],
            'price'          => $this->faker->randomFloat(2, 10, 1000),
            'original_price' => $this->faker->randomFloat(2, 1000, 2000),
            'stock'          => $this->faker->numberBetween(0, 1000),
            'weight'         => $this->faker->randomFloat(2, 0.1, 10),
            'sku_code'       => $this->faker->unique()->numerify('SKU########'),
            'image'          => $this->faker->imageUrl(400, 400),
            'sort_order'     => 0,
        ];
    }
}
