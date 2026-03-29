<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Token 刷新功能测试
 */
class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['X-Platform' => 'app'];

    /**
     * 测试：刷新 Token 成功返回新 Token 对
     */
    public function test_refresh_returns_new_token_pair(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $rawToken = Str::random(64);

        // 创建 Refresh Token 记录
        UserToken::create([
            'user_id'        => $user->id,
            'platform'       => 'app',
            'token'          => hash('sha256', $rawToken),
            'device_name'    => 'Test Device',
            'client_ip'      => '127.0.0.1',
            'user_agent'     => 'TestAgent/1.0',
            'last_jwt_jti'   => 'old-jti-123',
            'last_active_at' => now(),
            'expires_at'     => now()->addDays(30),
        ]);

        // Mock Redis 黑名单操作
        Redis::shouldReceive('setex')->andReturn(true);
        Redis::shouldReceive('exists')->andReturn(0);

        $response = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $rawToken,
        ], self::HEADERS);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'refresh_token',
                    'token_type',
                    'expires_in',
                ],
            ])
            ->assertJson([
                'code'    => 0,
                'message' => '刷新成功',
            ]);

        // 新的 refresh_token 应该和旧的不同（Token 旋转）
        $newRefreshToken = $response->json('data.refresh_token');
        $this->assertNotEquals($rawToken, $newRefreshToken);
    }

    /**
     * 测试：无效的 Refresh Token 返回 401
     */
    public function test_refresh_with_invalid_token_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => Str::random(64),
        ], self::HEADERS);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 40107,
            ]);
    }

    /**
     * 测试：过期的 Refresh Token 返回 401
     */
    public function test_refresh_with_expired_token_returns_401(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $rawToken = Str::random(64);

        UserToken::create([
            'user_id'        => $user->id,
            'platform'       => 'app',
            'token'          => hash('sha256', $rawToken),
            'device_name'    => 'Test Device',
            'client_ip'      => '127.0.0.1',
            'user_agent'     => 'TestAgent/1.0',
            'last_jwt_jti'   => 'old-jti-456',
            'last_active_at' => now()->subDays(31),
            'expires_at'     => now()->subDay(), // 已过期
        ]);

        $response = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $rawToken,
        ], self::HEADERS);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 40107,
            ]);
    }

    /**
     * 测试：Refresh Token 格式不正确返回 422
     */
    public function test_refresh_with_wrong_format_returns_422(): void
    {
        $response = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => 'too-short',
        ], self::HEADERS);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 49900,
            ]);
    }
}
