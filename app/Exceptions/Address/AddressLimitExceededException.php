<?php

namespace App\Exceptions\Address;

use App\Exceptions\BusinessException;

class AddressLimitExceededException extends BusinessException
{
    public function __construct(int $limit = 20)
    {
        parent::__construct(422, 46001, "地址数量超限: {$limit}", "最多保存{$limit}个收货地址");
    }
}
