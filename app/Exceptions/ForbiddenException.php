<?php

namespace App\Exceptions;

class ForbiddenException extends BusinessException
{
    public function __construct(string $action = '')
    {
        parent::__construct(403, 49002, "无权操作: {$action}", '您没有权限执行此操作');
    }
}
