<?php

namespace App\Exceptions\Auth;

use App\Exceptions\BusinessException;

class DeviceKickedException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(401, 40104, '设备被踢下线', '您的账号在其他设备登录');
    }
}
