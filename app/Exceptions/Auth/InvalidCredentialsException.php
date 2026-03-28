<?php

namespace App\Exceptions\Auth;

use App\Exceptions\BusinessException;

class InvalidCredentialsException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(401, 40101, '密码校验失败', '账号或密码错误');
    }
}
