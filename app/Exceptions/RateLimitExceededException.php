<?php

namespace App\Exceptions;

class RateLimitExceededException extends BusinessException
{
    public function __construct(string $action, int $retryAfter)
    {
        parent::__construct(
            429, 49001,
            "频率限制: {$action}",
            '操作过于频繁，请稍后再试',
            ['retry_after' => $retryAfter]
        );
    }
}
