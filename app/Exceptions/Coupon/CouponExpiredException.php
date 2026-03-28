<?php

namespace App\Exceptions\Coupon;

use App\Exceptions\BusinessException;

class CouponExpiredException extends BusinessException
{
    public function __construct(int $couponId)
    {
        parent::__construct(410, 45001, "优惠券已过期: {$couponId}", '优惠券已过期');
    }
}
