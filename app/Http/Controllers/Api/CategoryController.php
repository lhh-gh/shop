<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;

/**
 * 商品分类控制器
 */
class CategoryController extends Controller
{
    /**
     * 获取所有顶层分类及其子分类（树形结构）
     * 
     * GET /categories
     */
    public function index(): JsonResponse
    {
        $categories = Category::where('parent_id', 0)
            ->where('is_enabled', 1)
            ->with(['children' => function ($query) {
                $query->where('is_enabled', 1)->orderBy('sort_order', 'asc');
            }])
            ->orderBy('sort_order', 'asc')
            ->get();

        $data = CategoryResource::collection($categories)->resolve();

        return $this->success($data);
    }
}
