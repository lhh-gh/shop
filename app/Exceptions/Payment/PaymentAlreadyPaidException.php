<?php

namespace App\Exceptions\Payment;

use App\Exceptions\BusinessException;

class PaymentAlreadyPaidException extends BusinessException
{
    public function __construct(string $orderNo)
    {
        parent::__construct(409, 43003, "订单已支付: {$orderNo}", '订单已支付，无需重复支付');
    }
}
