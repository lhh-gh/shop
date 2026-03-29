<?php

namespace Tests\Unit\Services;

use App\Exceptions\Auth\SmsSendTooFrequentException;
use App\Services\Auth\SmsService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 短信服务单元测试
 */
class SmsServiceTest extends TestCase
{
    private SmsService $smsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smsService = new SmsService();
    }

    /**
     * 测试：发送验证码成功，生成6位数字并存入 Redis
     */
    public function test_send_generates_and_stores_code(): void
    {
        $phone = '13800138000';

        // 频率限制检查
        Redis::shouldReceive('get')
            ->with("sms_rate:min:{$phone}")->once()->andReturn(null);
        Redis::shouldReceive('get')
            ->with("sms_rate:hour:{$phone}")->once()->andReturn(null);
        Redis::shouldReceive('get')
            ->with("sms_rate:day:{$phone}")->once()->andReturn(null);

        // 存储验证码
        Redis::shouldReceive('setex')
            ->with("sms_code:{$phone}", 300, \Mockery::pattern('/^\d{6}$/'))->once();

        // 更新频率限制计数器
        Redis::shouldReceive('setex')
            ->with("sms_rate:min:{$phone}", 60, 1)->once();
        Redis::shouldReceive('incr')
            ->with("sms_rate:hour:{$phone}")->once()->andReturn(1);
        Redis::shouldReceive('expire')
            ->with("sms_rate:hour:{$phone}", 3600)->once();
        Redis::shouldReceive('incr')
            ->with("sms_rate:day:{$phone}")->once()->andReturn(1);
        Redis::shouldReceive('expire')
            ->with("sms_rate:day:{$phone}", 86400)->once();

        // 日志
        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('info')->once();

        $result = $this->smsService->send($phone);
        $this->assertTrue($result);
    }

    /**
     * 测试：每分钟频率限制触发时抛出异常
     */
    public function test_send_throws_on_minute_rate_limit(): void
    {
        $phone = '13800138000';

        Redis::shouldReceive('get')
            ->with("sms_rate:min:{$phone}")->once()->andReturn('1');

        // 频率限制命中时记录告警
        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $this->expectException(SmsSendTooFrequentException::class);
        $this->smsService->send($phone);
    }

    /**
     * 测试：每小时频率限制触发时抛出异常
     */
    public function test_send_throws_on_hour_rate_limit(): void
    {
        $phone = '13800138000';

        Redis::shouldReceive('get')
            ->with("sms_rate:min:{$phone}")->once()->andReturn(null);
        Redis::shouldReceive('get')
            ->with("sms_rate:hour:{$phone}")->once()->andReturn('5');

        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $this->expectException(SmsSendTooFrequentException::class);
        $this->smsService->send($phone);
    }

    /**
     * 测试：每天频率限制触发时抛出异常
     */
    public function test_send_throws_on_day_rate_limit(): void
    {
        $phone = '13800138000';

        Redis::shouldReceive('get')
            ->with("sms_rate:min:{$phone}")->once()->andReturn(null);
        Redis::shouldReceive('get')
            ->with("sms_rate:hour:{$phone}")->once()->andReturn('4');
        Redis::shouldReceive('get')
            ->with("sms_rate:day:{$phone}")->once()->andReturn('10');

        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $this->expectException(SmsSendTooFrequentException::class);
        $this->smsService->send($phone);
    }

    /**
     * 测试：验证码正确时验证成功
     */
    public function test_verify_returns_true_on_correct_code(): void
    {
        $phone = '13800138000';

        Redis::shouldReceive('get')
            ->with("sms_code:{$phone}")->once()->andReturn('654321');
        Redis::shouldReceive('del')
            ->with("sms_code:{$phone}")->once()->andReturn(1);

        $this->assertTrue($this->smsService->verify($phone, '654321'));
    }

    /**
     * 测试：验证码错误时验证失败
     */
    public function test_verify_returns_false_on_wrong_code(): void
    {
        $phone = '13800138000';

        Redis::shouldReceive('get')
            ->with("sms_code:{$phone}")->once()->andReturn('654321');

        $this->assertFalse($this->smsService->verify($phone, '000000'));
    }

    /**
     * 测试：验证码不存在时验证失败
     */
    public function test_verify_returns_false_when_no_code_stored(): void
    {
        $phone = '13800138000';

        Redis::shouldReceive('get')
            ->with("sms_code:{$phone}")->once()->andReturn(null);

        $this->assertFalse($this->smsService->verify($phone, '123456'));
    }
}
