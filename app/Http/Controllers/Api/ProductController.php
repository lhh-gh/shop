<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Product;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;

/**
 * 商品 SPU 控制器
 */
class ProductController extends Controller
{
    /**
     * 获取上架商品列表（支持分页及条件过滤）
     * 
     * GET /products
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::where('status', 1)->with(['category']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('keyword')) {
            $query->where('title', 'like', '%' . $request->input('keyword') . '%');
        }

        $limit = (int) $request->input('limit', 15);
        $paginator = $query->orderBy('id', 'desc')->paginate($limit);

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
        $product = Product::where('status', 1)
            ->with(['category', 'skus', 'attributes'])
            ->findOrFail($id);

        $data = (new ProductResource($product))->resolve();

        return $this->success($data);
    }
}
