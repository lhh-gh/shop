<?php

namespace App\Exceptions\Auth;

use App\Exceptions\BusinessException;

class SocialAuthException extends BusinessException
{
    public function __construct(
        string $message = '社交登录授权失败',
        string $userMessage = '第三方授权失败，请稍后重试',
        int $httpStatus = 401,
        int $errorCode = 40108,
        array $data = []
    ) {
        parent::__construct($httpStatus, $errorCode, $message, $userMessage, $data);
    }
}
