<?php

namespace App\Exceptions\Auth;

use App\Exceptions\BusinessException;

class SmsCodeInvalidException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(401, 40102, '短信验证码不匹配', '验证码错误或已过期');
    }
}
