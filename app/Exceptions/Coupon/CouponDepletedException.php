<?php

namespace App\Exceptions\Coupon;

use App\Exceptions\BusinessException;

class CouponDepletedException extends BusinessException
{
    public function __construct(int $couponId)
    {
        parent::__construct(410, 45003, "优惠券已领完: {$couponId}", '优惠券已被领完');
    }
}
