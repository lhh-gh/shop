<?php

namespace App\Exceptions\Auth;

use App\Exceptions\BusinessException;

class SmsSendTooFrequentException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(429, 40106, '短信发送频率过高', '操作过于频繁，请稍后再试');
    }
}
