<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Category;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_category_tree()
    {
        $root = Category::factory()->create(['name' => 'Electronics', 'sort_order' => 1]);
        $child = Category::factory()->create(['parent_id' => $root->id, 'name' => 'Phones', 'sort_order' => 1]);

        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200)
            ->assertJsonPath('code', 0)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Electronics')
            ->assertJsonPath('data.0.children.0.name', 'Phones');
    }
}
