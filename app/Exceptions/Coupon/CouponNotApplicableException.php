<?php

namespace App\Exceptions\Coupon;

use App\Exceptions\BusinessException;

class CouponNotApplicableException extends BusinessException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 45004, "优惠券不满足使用条件: {$reason}", '不满足优惠券使用条件');
    }
}
