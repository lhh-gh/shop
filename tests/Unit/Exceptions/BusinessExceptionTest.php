<?php

namespace Tests\Unit\Exceptions;

use Tests\TestCase;
use App\Exceptions\BusinessException;

// 测试用的具体异常子类
class TestBusinessException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(422, 99001, '内部调试信息', '用户友好消息');
    }
}

class TestBusinessExceptionWithData extends BusinessException
{
    public function __construct()
    {
        parent::__construct(422, 99002, '调试', '带数据的异常', ['field' => 'value']);
    }
}

class BusinessExceptionTest extends TestCase
{
    public function test_exception_carries_http_status(): void
    {
        $e = new TestBusinessException();
        $this->assertEquals(422, $e->getHttpStatus());
    }

    public function test_exception_carries_error_code(): void
    {
        $e = new TestBusinessException();
        $this->assertEquals(99001, $e->getErrorCode());
    }

    public function test_exception_carries_user_message(): void
    {
        $e = new TestBusinessException();
        $this->assertEquals('用户友好消息', $e->getUserMessage());
    }

    public function test_exception_internal_message_is_getMessage(): void
    {
        $e = new TestBusinessException();
        $this->assertEquals('内部调试信息', $e->getMessage());
    }

    public function test_exception_carries_data(): void
    {
        $e = new TestBusinessExceptionWithData();
        $this->assertEquals(['field' => 'value'], $e->getData());
    }

    public function test_exception_default_data_is_empty_array(): void
    {
        $e = new TestBusinessException();
        $this->assertEquals([], $e->getData());
    }

    public function test_exception_code_equals_error_code(): void
    {
        $e = new TestBusinessException();
        $this->assertEquals(99001, $e->getCode());
    }
}
