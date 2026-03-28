<?php

namespace App\Exceptions\Auth;

use App\Exceptions\BusinessException;

class UserDisabledException extends BusinessException
{
    public function __construct(int $userId)
    {
        parent::__construct(403, 40105, "用户已禁用: {$userId}", '您的账号已被禁用');
    }
}
