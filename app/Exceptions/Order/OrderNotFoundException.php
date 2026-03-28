<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class OrderNotFoundException extends BusinessException
{
    public function __construct(string $orderNo)
    {
        parent::__construct(404, 42001, "订单不存在: {$orderNo}", '订单不存在');
    }
}
