<?php

namespace Tests\Unit\Services\Auth;

use Tests\TestCase;
use App\Services\Auth\JwtService;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Mockery;

class JwtServiceTest extends TestCase
{
    protected JwtService $jwtService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwtService = new JwtService();
    }

    public function test_generate_creates_token_with_custom_claims()
    {
        $user = User::factory()->make(['id' => 1]);
        $platform = 'web';
        $deviceName = 'Chrome Browser';

        $mockFactory = Mockery::mock();
        $mockFactory->shouldReceive('setTTL')->with(120)->andReturnSelf();

        JWTAuth::shouldReceive('factory')->once()->andReturn($mockFactory);
        JWTAuth::shouldReceive('customClaims')
            ->once()
            ->with(Mockery::on(function ($claims) use ($platform, $deviceName) {
                return isset($claims['jti'])
                    && $claims['platform'] === $platform
                    && $claims['device_name'] === $deviceName;
            }))
            ->andReturnSelf();
        JWTAuth::shouldReceive('fromUser')
            ->once()
            ->with($user)
            ->andReturn('mock.jwt.token');

        $result = $this->jwtService->generate($user, $platform, $deviceName);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('jwt', $result);
        $this->assertArrayHasKey('jti', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertEquals('mock.jwt.token', $result['jwt']);
        $this->assertIsString($result['jti']);
        $this->assertInstanceOf(Carbon::class, $result['expires_at']);
    }

    public function test_parse_extracts_claims_from_valid_token()
    {
        $token = 'valid.jwt.token';
        $mockPayload = Mockery::mock();
        $mockPayload->shouldReceive('get')->with('sub')->andReturn(1);
        $mockPayload->shouldReceive('get')->with('platform')->andReturn('web');
        $mockPayload->shouldReceive('get')->with('jti')->andReturn('test-jti-123');
        $mockPayload->shouldReceive('get')->with('device_name')->andReturn('Chrome');

        JWTAuth::shouldReceive('setToken')->with($token)->andReturnSelf();
        JWTAuth::shouldReceive('getPayload')->andReturn($mockPayload);

        $result = $this->jwtService->parse($token);

        $this->assertEquals([
            'user_id' => 1,
            'platform' => 'web',
            'jti' => 'test-jti-123',
            'device_name' => 'Chrome',
        ], $result);
    }

    public function test_parse_throws_exception_for_invalid_token()
    {
        $token = 'invalid.jwt.token';

        JWTAuth::shouldReceive('setToken')->with($token)->andReturnSelf();
        JWTAuth::shouldReceive('getPayload')->andThrow(new \Exception('Token invalid'));

        Log::shouldReceive('channel')->with('auth')->andReturnSelf();
        Log::shouldReceive('error')->once();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid token');

        $this->jwtService->parse($token);
    }

    public function test_verify_returns_true_for_valid_token()
    {
        $token = 'valid.jwt.token';

        JWTAuth::shouldReceive('setToken')->with($token)->andReturnSelf();
        JWTAuth::shouldReceive('check')->andReturn(true);

        $result = $this->jwtService->verify($token);

        $this->assertTrue($result);
    }

    public function test_verify_returns_false_for_invalid_token()
    {
        $token = 'invalid.jwt.token';

        JWTAuth::shouldReceive('setToken')->with($token)->andReturnSelf();
        JWTAuth::shouldReceive('check')->andThrow(new \Exception('Token expired'));

        Log::shouldReceive('channel')->with('auth')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = $this->jwtService->verify($token);

        $this->assertFalse($result);
    }

    public function test_blacklist_adds_jti_to_redis()
    {
        $jti = 'test-jti-123';
        $ttl = 7200;

        Redis::shouldReceive('setex')
            ->once()
            ->with("jwt_blacklist:{$jti}", $ttl, '1');

        $this->jwtService->blacklist($jti, $ttl);
    }

    public function test_blacklist_handles_redis_failure_gracefully()
    {
        $jti = 'test-jti-123';
        $ttl = 7200;

        Redis::shouldReceive('setex')
            ->once()
            ->andThrow(new \Exception('Redis connection failed'));

        Log::shouldReceive('channel')->with('auth')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with(
            'Failed to blacklist JWT',
            Mockery::on(function ($context) use ($jti) {
                return $context['jti'] === $jti
                    && isset($context['error']);
            })
        );

        $this->jwtService->blacklist($jti, $ttl);
    }

    public function test_is_blacklisted_returns_true_when_jti_exists()
    {
        $jti = 'test-jti-123';

        Redis::shouldReceive('exists')
            ->once()
            ->with("jwt_blacklist:{$jti}")
            ->andReturn(1);

        $result = $this->jwtService->isBlacklisted($jti);

        $this->assertTrue($result);
    }

    public function test_is_blacklisted_returns_false_when_jti_not_exists()
    {
        $jti = 'test-jti-123';

        Redis::shouldReceive('exists')
            ->once()
            ->with("jwt_blacklist:{$jti}")
            ->andReturn(0);

        $result = $this->jwtService->isBlacklisted($jti);

        $this->assertFalse($result);
    }

    public function test_is_blacklisted_returns_false_on_redis_failure()
    {
        $jti = 'test-jti-123';

        Redis::shouldReceive('exists')
            ->once()
            ->andThrow(new \Exception('Redis connection failed'));

        Log::shouldReceive('channel')->with('auth')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with(
            'Failed to check JWT blacklist',
            Mockery::on(function ($context) use ($jti) {
                return $context['jti'] === $jti
                    && isset($context['error']);
            })
        );

        $result = $this->jwtService->isBlacklisted($jti);

        $this->assertFalse($result);
    }
}
