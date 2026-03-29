<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Http\Resources\Api\V1\CategoryResource;
use Illuminate\Http\JsonResponse;

/**
 * 商品分类控制器
 */
class CategoryController extends Controller
{
    private CategoryRepositoryInterface $categoryRepo;

    public function __construct(CategoryRepositoryInterface $categoryRepo)
    {
        $this->categoryRepo = $categoryRepo;
    }

    /**
     * 获取所有顶层分类及其子分类（树形结构）
     * 
     * GET /categories
     */
    public function index(): JsonResponse
    {
        $categories = $this->categoryRepo->getCategoryTree();

        $data = CategoryResource::collection($categories)->resolve();

        return $this->success($data);
    }
}
