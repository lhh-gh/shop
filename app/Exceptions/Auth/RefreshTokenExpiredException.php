<?php

namespace App\Exceptions\Auth;

use App\Exceptions\BusinessException;

class RefreshTokenExpiredException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(401, 40107, 'Refresh Token 已过期', '登录已过期，请重新登录');
    }
}
