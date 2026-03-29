<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 密码登录功能测试
 */
class LoginByPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['X-Platform' => 'pc'];

    /**
     * 测试：密码登录成功
     */
    public function test_password_login_success(): void
    {
        $user = User::factory()->create([
            'phone'    => '13900139001',
            'password' => Hash::make('password123'),
            'status'   => 1,
        ]);

        $response = $this->postJson('/api/v1/auth/login/password', [
            'account'  => '13900139001',
            'password' => 'password123',
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
    }

    /**
     * 测试：密码错误返回 401
     */
    public function test_password_login_wrong_password_returns_401(): void
    {
        User::factory()->create([
            'phone'    => '13900139002',
            'password' => Hash::make('password123'),
            'status'   => 1,
        ]);

        $response = $this->postJson('/api/v1/auth/login/password', [
            'account'  => '13900139002',
            'password' => 'wrongpassword',
        ], self::HEADERS);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 40101,
            ]);
    }

    /**
     * 测试：用户不存在返回 401
     */
    public function test_password_login_nonexistent_user_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login/password', [
            'account'  => '13900139099',
            'password' => 'password123',
        ], self::HEADERS);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 40101,
            ]);
    }

    /**
     * 测试：邮箱登录成功
     */
    public function test_email_login_success(): void
    {
        User::factory()->create([
            'email'    => 'test@example.com',
            'password' => Hash::make('password123'),
            'status'   => 1,
        ]);

        $response = $this->postJson('/api/v1/auth/login/password', [
            'account'  => 'test@example.com',
            'password' => 'password123',
        ], self::HEADERS);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
            ]);
    }

    /**
     * 测试：参数验证失败
     */
    public function test_password_login_validation_fails(): void
    {
        $response = $this->postJson('/api/v1/auth/login/password', [
            'account' => '',  // 空账号
        ], self::HEADERS);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 49900,
            ]);
    }
}
