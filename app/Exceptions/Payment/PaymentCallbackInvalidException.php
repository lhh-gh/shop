<?php

namespace App\Exceptions\Payment;

use App\Exceptions\BusinessException;

class PaymentCallbackInvalidException extends BusinessException
{
    public function __construct(string $channel, string $reason)
    {
        parent::__construct(400, 43002, "支付回调验签失败: {$channel} - {$reason}", '');
    }
}
