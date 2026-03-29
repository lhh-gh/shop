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
 * 设备管理功能测试
 */
class DeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 辅助方法：为用户创建 JWT Token 并返回请求头
     */
    private function authHeaders(User $user, string $platform = 'app'): array
    {
        $token = JWTAuth::customClaims([
            'jti'         => Str::uuid()->toString(),
            'platform'    => $platform,
            'device_name' => 'Test Device',
        ])->fromUser($user);

        return [
            'Authorization' => "Bearer {$token}",
            'X-Platform'    => $platform,
        ];
    }

    /**
     * 测试：获取设备列表
     */
    public function test_list_devices_returns_active_sessions(): void
    {
        $user = User::factory()->create(['status' => 1]);

        // 创建两个设备的 Token
        foreach (['app', 'pc'] as $platform) {
            UserToken::create([
                'user_id'        => $user->id,
                'platform'       => $platform,
                'token'          => hash('sha256', Str::random(64)),
                'device_name'    => "Test {$platform}",
                'client_ip'      => '127.0.0.1',
                'user_agent'     => 'TestAgent',
                'last_jwt_jti'   => Str::uuid()->toString(),
                'last_active_at' => now(),
                'expires_at'     => now()->addDays(30),
            ]);
        }

        // Mock Redis 黑名单检查
        Redis::shouldReceive('exists')->andReturn(0);

        $response = $this->getJson('/api/v1/auth/devices', $this->authHeaders($user));

        $response->assertStatus(200)
            ->assertJson(['code' => 0])
            ->assertJsonCount(2, 'data');
    }

    /**
     * 测试：踢掉指定设备
     */
    public function test_kick_device_removes_it(): void
    {
        $user = User::factory()->create(['status' => 1]);

        // 当前设备 app，目标踢掉 pc
        UserToken::create([
            'user_id'        => $user->id,
            'platform'       => 'pc',
            'token'          => hash('sha256', Str::random(64)),
            'device_name'    => 'Test PC',
            'client_ip'      => '127.0.0.1',
            'user_agent'     => 'TestAgent',
            'last_jwt_jti'   => 'pc-jti-123',
            'last_active_at' => now(),
            'expires_at'     => now()->addDays(30),
        ]);

        // Mock Redis
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturn(true);

        $response = $this->deleteJson('/api/v1/auth/devices/pc', [], $this->authHeaders($user));

        $response->assertStatus(200)
            ->assertJson([
                'code'    => 0,
                'message' => '设备已下线',
            ]);

        // 验证数据库中已删除
        $this->assertDatabaseMissing('user_tokens', [
            'user_id'  => $user->id,
            'platform' => 'pc',
        ]);
    }

    /**
     * 测试：不能踢掉自己当前登录的平台
     */
    public function test_cannot_kick_current_platform(): void
    {
        $user = User::factory()->create(['status' => 1]);

        // Mock Redis
        Redis::shouldReceive('exists')->andReturn(0);

        $response = $this->deleteJson(
            '/api/v1/auth/devices/app',
            [],
            $this->authHeaders($user, 'app')
        );

        $response->assertStatus(400)
            ->assertJson([
                'code' => 40002,
            ]);
    }

    /**
     * 测试：未认证用户访问设备列表返回 401
     */
    public function test_unauthenticated_user_cannot_list_devices(): void
    {
        $response = $this->getJson('/api/v1/auth/devices', [
            'X-Platform' => 'app',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 测试：无效平台标识返回 400
     */
    public function test_kick_invalid_platform_returns_400(): void
    {
        $user = User::factory()->create(['status' => 1]);

        Redis::shouldReceive('exists')->andReturn(0);

        $response = $this->deleteJson(
            '/api/v1/auth/devices/invalid_platform',
            [],
            $this->authHeaders($user, 'app')
        );

        $response->assertStatus(400)
            ->assertJson([
                'code' => 40001,
            ]);
    }
}
