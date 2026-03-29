<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Product\ProductService;
use App\Http\Resources\Api\V1\ProductResource;
use Illuminate\Http\JsonResponse;

/**
 * 商品 SPU 控制器
 */
class ProductController extends Controller
{
    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    /**
     * 获取上架商品列表（支持分页及条件过滤）
     * 
     * GET /products
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['category_id', 'keyword']);
        $limit = (int) $request->input('limit', 15);

        $paginator = $this->productService->getList($filters, $limit);

        $paginator->through(fn ($product) => (new ProductResource($product))->resolve());

        return $this->paginated($paginator);
    }

    /**
     * 获取商品详情（包含关联的 SKU 列表）
     * 
     * GET /products/{id}
     */
    public function show(int $id): JsonResponse
    {
        $product = $this->productService->getDetail($id);

        $data = (new ProductResource($product))->resolve();

        return $this->success($data);
    }
}
