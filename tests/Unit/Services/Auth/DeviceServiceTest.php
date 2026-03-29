<?php

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\DeviceService;
use App\Services\Auth\JwtService;
use App\Repositories\UserTokenRepository;
use App\Repositories\SecurityLogRepository;
use App\Models\UserToken;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Tests\TestCase;

class DeviceServiceTest extends TestCase
{
    private DeviceService $service;
    private JwtService $jwtService;
    private UserTokenRepository $tokenRepo;
    private SecurityLogRepository $securityLogRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jwtService = Mockery::mock(JwtService::class);
        $this->tokenRepo = Mockery::mock(UserTokenRepository::class);
        $this->securityLogRepo = Mockery::mock(SecurityLogRepository::class);

        $this->service = new DeviceService(
            $this->jwtService,
            $this->tokenRepo,
            $this->securityLogRepo
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_returns_empty_array_when_no_tokens(): void
    {
        $userId = 1;

        $this->tokenRepo
            ->shouldReceive('findActiveByUser')
            ->once()
            ->with($userId)
            ->andReturn(new Collection());

        $result = $this->service->list($userId);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_list_returns_device_info_with_masked_ip(): void
    {
        $userId = 1;
        $now = Carbon::now();

        $token1 = new UserToken([
            'platform' => 'ios',
            'device_name' => 'iPhone 13',
            'client_ip' => '192.168.1.100',
            'last_active_at' => $now->copy()->subMinutes(10),
            'expires_at' => $now->copy()->addDays(30),
        ]);

        $token2 = new UserToken([
            'platform' => 'android',
            'device_name' => 'Samsung Galaxy',
            'client_ip' => '10.0.0.50',
            'last_active_at' => $now->copy()->subHours(2),
            'expires_at' => $now->copy()->addDays(30),
        ]);

        $this->tokenRepo
            ->shouldReceive('findActiveByUser')
            ->once()
            ->with($userId)
            ->andReturn(new Collection([$token1, $token2]));

        $result = $this->service->list($userId);

        $this->assertCount(2, $result);
        $this->assertEquals('ios', $result[0]['platform']);
        $this->assertEquals('iPhone 13', $result[0]['device_name']);
        $this->assertEquals('192.168.1.*', $result[0]['client_ip']);
        $this->assertFalse($result[0]['is_current']);
    }

    public function test_list_marks_current_device_based_on_platform(): void
    {
        $userId = 1;
        $now = Carbon::now();

        $token = new UserToken([
            'platform' => 'web',
            'device_name' => 'Chrome Browser',
            'client_ip' => '192.168.1.100',
            'last_active_at' => $now,
            'expires_at' => $now->copy()->addDays(30),
        ]);

        $this->tokenRepo
            ->shouldReceive('findActiveByUser')
            ->once()
            ->with($userId)
            ->andReturn(new Collection([$token]));

        // Mock request to return 'web' platform
        request()->headers->set('X-Platform', 'web');

        $result = $this->service->list($userId);

        $this->assertTrue($result[0]['is_current']);
    }

    public function test_kick_blacklists_jwt_and_deletes_token(): void
    {
        $userId = 1;
        $platform = 'ios';
        $jti = 'test-jti-123';
        $ttl = 7200; // 2 hours in seconds

        $token = new UserToken([
            'user_id' => $userId,
            'platform' => $platform,
            'last_jwt_jti' => $jti,
            'expires_at' => Carbon::now()->addHours(2),
        ]);

        $this->tokenRepo
            ->shouldReceive('findActiveByUser')
            ->once()
            ->with($userId)
            ->andReturn(new Collection([$token]));

        $this->jwtService
            ->shouldReceive('blacklist')
            ->once()
            ->with($jti, Mockery::type('int'));

        $this->tokenRepo
            ->shouldReceive('deleteByUserAndPlatform')
            ->once()
            ->with($userId, $platform)
            ->andReturn(true);

        $this->securityLogRepo
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($data) use ($userId, $platform) {
                return $data['user_id'] === $userId
                    && $data['event'] === 'device_kicked'
                    && $data['context']['platform'] === $platform;
            }));

        $this->service->kick($userId, $platform);
    }

    public function test_kick_does_nothing_when_token_not_found(): void
    {
        $userId = 1;
        $platform = 'ios';

        $this->tokenRepo
            ->shouldReceive('findActiveByUser')
            ->once()
            ->with($userId)
            ->andReturn(new Collection());

        $this->jwtService->shouldNotReceive('blacklist');
        $this->tokenRepo->shouldNotReceive('deleteByUserAndPlatform');
        $this->securityLogRepo->shouldNotReceive('create');

        $this->service->kick($userId, $platform);
    }

    public function test_kickAll_blacklists_all_tokens(): void
    {
        $userId = 1;
        $now = Carbon::now();

        $token1 = new UserToken([
            'platform' => 'ios',
            'last_jwt_jti' => 'jti-1',
            'expires_at' => $now->copy()->addHours(2),
        ]);

        $token2 = new UserToken([
            'platform' => 'android',
            'last_jwt_jti' => 'jti-2',
            'expires_at' => $now->copy()->addHours(1),
        ]);

        $this->tokenRepo
            ->shouldReceive('findActiveByUser')
            ->once()
            ->with($userId)
            ->andReturn(new Collection([$token1, $token2]));

        $this->jwtService
            ->shouldReceive('blacklist')
            ->once()
            ->with('jti-1', Mockery::type('int'));

        $this->jwtService
            ->shouldReceive('blacklist')
            ->once()
            ->with('jti-2', Mockery::type('int'));

        $this->tokenRepo
            ->shouldReceive('deleteByUserAndPlatform')
            ->once()
            ->with($userId, 'ios');

        $this->tokenRepo
            ->shouldReceive('deleteByUserAndPlatform')
            ->once()
            ->with($userId, 'android');

        $this->securityLogRepo
            ->shouldReceive('create')
            ->twice();

        $this->service->kickAll($userId);
    }

    public function test_kickAll_excludes_current_platform(): void
    {
        $userId = 1;
        $now = Carbon::now();

        $token1 = new UserToken([
            'platform' => 'web',
            'last_jwt_jti' => 'jti-1',
            'expires_at' => $now->copy()->addHours(2),
        ]);

        $token2 = new UserToken([
            'platform' => 'ios',
            'last_jwt_jti' => 'jti-2',
            'expires_at' => $now->copy()->addHours(1),
        ]);

        $this->tokenRepo
            ->shouldReceive('findActiveByUser')
            ->once()
            ->with($userId)
            ->andReturn(new Collection([$token1, $token2]));

        // Only ios should be blacklisted
        $this->jwtService
            ->shouldReceive('blacklist')
            ->once()
            ->with('jti-2', Mockery::type('int'));

        $this->tokenRepo
            ->shouldReceive('deleteByUserAndPlatform')
            ->once()
            ->with($userId, 'ios');

        $this->securityLogRepo
            ->shouldReceive('create')
            ->once();

        $this->service->kickAll($userId, 'web');
    }
}
