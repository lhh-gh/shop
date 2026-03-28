<?php

namespace App\Exceptions;

use Exception;

/**
 * 所有业务异常的基类
 * 携带 HTTP 状态码 + 业务错误码 + 用户友好消息
 */
abstract class BusinessException extends Exception
{
    public function __construct(
        protected int    $httpStatus,
        protected int    $errorCode,
        string           $message = '',
        protected string $userMessage = '操作失败，请稍后重试',
        protected array  $data = [],
        ?\Throwable      $previous = null,
    ) {
        parent::__construct($message ?: $userMessage, $errorCode, $previous);
    }

    public function getHttpStatus(): int     { return $this->httpStatus; }
    public function getErrorCode(): int      { return $this->errorCode; }
    public function getUserMessage(): string { return $this->userMessage; }
    public function getData(): array         { return $this->data; }
}
