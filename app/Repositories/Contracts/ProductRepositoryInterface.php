<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Models\Product;

interface ProductRepositoryInterface
{
    /**
     * 获取上架商品列表（支持分页及多维筛选）
     */
    public function getList(array $filters, int $limit = 15): LengthAwarePaginator;

    /**
     * 获取商品详情（含相关的 SKU 和属性）
     */
    public function getDetail(int $id): Product;
}
