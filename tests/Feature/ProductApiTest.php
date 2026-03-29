<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Product;
use App\Models\Category;
use App\Models\ProductSku;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_product_list()
    {
        Product::factory()->count(5)->create();

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJsonPath('code', 0)
            ->assertJsonCount(5, 'data.items')
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => ['id', 'title']
                    ],
                    'pagination' => ['total', 'current_page', 'last_page']
                ]
            ]);
    }

    public function test_get_product_list_with_filters()
    {
        $category = Category::factory()->create();

        Product::factory()->create(['title' => 'Test Phone A', 'category_id' => $category->id]);
        Product::factory()->create(['title' => 'Other Gadget']);

        $response = $this->getJson('/api/v1/products?category_id=' . $category->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Test Phone A');
    }

    public function test_get_product_detail_with_skus()
    {
        $product = Product::factory()->create(['title' => 'Awesome Shirt']);
        $sku1 = ProductSku::factory()->create(['product_id' => $product->id, 'price' => 99.99]);
        $sku2 = ProductSku::factory()->create(['product_id' => $product->id, 'price' => 109.99]);

        $response = $this->getJson('/api/v1/products/' . $product->id);

        $response->assertStatus(200)
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.title', 'Awesome Shirt')
            ->assertJsonCount(2, 'data.skus')
            ->assertJsonPath('data.skus.0.price', '99.99');
    }
}
