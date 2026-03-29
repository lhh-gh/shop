<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 短信登录功能测试
 */
class LoginBySmsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['X-Platform' => 'app'];

    /**
     * 测试：短信登录成功（新用户自动注册）
     */
    public function test_sms_login_success_creates_new_user(): void
    {
        $phone = '13800138001';

        // 在 Redis 中预设验证码
        Redis::shouldReceive('get')
            ->with("sms_code:{$phone}")
            ->once()
            ->andReturn('123456');

        Redis::shouldReceive('del')
            ->with("sms_code:{$phone}")
            ->once()
            ->andReturn(1);

        // 黑名单检查（kickSamePlatform 中不会触发，因为没有旧 Token）
        // 无需额外 mock

        $response = $this->postJson('/api/v1/auth/login/sms', [
            'phone' => $phone,
            'code'  => '123456',
        ], self::HEADERS);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'access_token',
                    'refresh_token',
                    'token_type',
                    'expires_in',
                    'user' => ['id', 'phone', 'nickname'],
                ],
            ])
            ->assertJson([
                'code'    => 0,
                'message' => '登录成功',
            ]);

        // 验证用户已创建
        $this->assertDatabaseHas('users', ['phone' => $phone]);
    }

    /**
     * 测试：验证码错误返回 401
     */
    public function test_sms_login_with_invalid_code_returns_401(): void
    {
        $phone = '13800138002';

        Redis::shouldReceive('get')
            ->with("sms_code:{$phone}")
            ->once()
            ->andReturn('654321');

        $response = $this->postJson('/api/v1/auth/login/sms', [
            'phone' => $phone,
            'code'  => '123456',
        ], self::HEADERS);

        $response->assertStatus(401)
            ->assertJson([
                'code'    => 40102,
                'message' => '验证码错误或已过期',
            ]);
    }

    /**
     * 测试：缺少平台标识返回 400
     */
    public function test_sms_login_without_platform_returns_400(): void
    {
        $response = $this->postJson('/api/v1/auth/login/sms', [
            'phone' => '13800138003',
            'code'  => '123456',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'code' => 40001,
            ]);
    }

    /**
     * 测试：参数验证失败
     */
    public function test_sms_login_validation_fails(): void
    {
        $response = $this->postJson('/api/v1/auth/login/sms', [
            'phone' => '12345',  // 格式错误
        ], self::HEADERS);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 49900,
            ]);
    }

    /**
     * 测试：已禁用用户登录返回 403
     */
    public function test_sms_login_disabled_user_returns_403(): void
    {
        $phone = '13800138004';

        // 创建已禁用的用户
        User::factory()->create([
            'phone'  => $phone,
            'status' => 0,
        ]);

        Redis::shouldReceive('get')
            ->with("sms_code:{$phone}")
            ->once()
            ->andReturn('123456');

        Redis::shouldReceive('del')
            ->with("sms_code:{$phone}")
            ->once()
            ->andReturn(1);

        $response = $this->postJson('/api/v1/auth/login/sms', [
            'phone' => $phone,
            'code'  => '123456',
        ], self::HEADERS);

        $response->assertStatus(403)
            ->assertJson([
                'code' => 40105,
            ]);
    }
}
