<?php

namespace App\Exceptions\Payment;

use App\Exceptions\BusinessException;

class PaymentGatewayException extends BusinessException
{
    public function __construct(string $channel, string $reason)
    {
        parent::__construct(
            502, 43001,
            "支付网关错误: {$channel} - {$reason}",
            '支付通道暂时不可用，请稍后重试'
        );
    }
}
