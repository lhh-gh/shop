<?php

namespace App\Exceptions\Auth;

use App\Exceptions\BusinessException;

class TokenLeakException extends BusinessException
{
    public function __construct(int $userId, string $platform)
    {
        parent::__construct(
            401, 40103,
            "Token泄露检测: user={$userId}, platform={$platform}",
            '检测到账号异常，请重新登录'
        );
    }
}
