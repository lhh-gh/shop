<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginBySocialTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['X-Platform' => 'app'];

    public function test_wechat_login_success(): void
    {
        $response = $this->postJson('/api/v1/auth/login/wechat', [
            'code' => 'valid_wechat_code',
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
                    'user' => ['id', 'nickname', 'avatar'],
                ],
            ])
            ->assertJson([
                'code' => 0,
                'message' => '登录成功',
            ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('user_social_accounts', [
            'platform' => 'wechat_app',
        ]);
    }

    public function test_alipay_login_success(): void
    {
        $response = $this->postJson('/api/v1/auth/login/alipay', [
            'code' => 'valid_alipay_code',
        ], self::HEADERS);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'message' => '登录成功',
            ]);

        $this->assertDatabaseHas('user_social_accounts', [
            'platform' => 'alipay',
        ]);
    }

    public function test_wechat_login_with_invalid_code_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login/wechat', [
            'code' => 'invalid_code',
        ], self::HEADERS);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 40108,
            ]);
    }

    public function test_social_login_requires_platform_header(): void
    {
        $response = $this->postJson('/api/v1/auth/login/wechat', [
            'code' => 'valid_wechat_code',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'code' => 40001,
            ]);
    }

    public function test_social_login_validation_fails(): void
    {
        $response = $this->postJson('/api/v1/auth/login/alipay', [], self::HEADERS);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 49900,
            ]);
    }
}
