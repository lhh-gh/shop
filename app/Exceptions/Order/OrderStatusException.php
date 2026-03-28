<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class OrderStatusException extends BusinessException
{
    public function __construct(string $orderNo, string $from, string $to)
    {
        parent::__construct(
            409, 42002,
            "订单状态不允许转换: {$orderNo} {$from}→{$to}",
            '当前订单状态不支持此操作'
        );
    }
}
