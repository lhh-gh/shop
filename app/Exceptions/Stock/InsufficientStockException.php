<?php

namespace App\Exceptions\Stock;

use App\Exceptions\BusinessException;

class InsufficientStockException extends BusinessException
{
    public function __construct(int $skuId, int $requested, int $available)
    {
        parent::__construct(
            422, 44001,
            "库存不足: sku={$skuId}, 需要={$requested}, 可用={$available}",
            '商品库存不足',
            ['sku_id' => $skuId, 'available' => $available]
        );
    }
}
