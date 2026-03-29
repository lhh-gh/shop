<?php

namespace App\Services\Product;

use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductService
{
    private ProductRepositoryInterface $productRepo;

    public function __construct(ProductRepositoryInterface $productRepo)
    {
        $this->productRepo = $productRepo;
    }

    /**
     * 获取商品列表
     */
    public function getList(array $filters, int $limit = 15): LengthAwarePaginator
    {
        return $this->productRepo->getList($filters, $limit);
    }

    /**
     * 获取商品详情
     */
    public function getDetail(int $id): Product
    {
        return $this->productRepo->getDetail($id);
    }
}
