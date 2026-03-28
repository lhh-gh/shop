<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class OrderExpiredException extends BusinessException
{
    public function __construct(string $orderNo)
    {
        parent::__construct(410, 42004, "订单已超时: {$orderNo}", '订单已超时关闭');
    }
}
