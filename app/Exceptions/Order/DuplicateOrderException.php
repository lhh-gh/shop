<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class DuplicateOrderException extends BusinessException
{
    public function __construct(string $idempotencyToken)
    {
        parent::__construct(409, 42003, "重复下单: {$idempotencyToken}", '订单已提交，请勿重复操作');
    }
}
