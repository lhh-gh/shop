<?php

namespace App\Exceptions\Coupon;

use App\Exceptions\BusinessException;

class CouponAlreadyClaimedException extends BusinessException
{
    public function __construct(int $userId, int $couponId)
    {
        parent::__construct(409, 45002, "重复领券: user={$userId}, coupon={$couponId}", '您已领取过该优惠券');
    }
}
