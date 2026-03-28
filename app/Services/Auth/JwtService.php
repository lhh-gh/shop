<?php

namespace App\Services\Auth;

use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Str;

class JwtService
{
    /**
     * Generate a JWT token for the user.
     *
     * @param User $user
     * @param string $platform
     * @param string $deviceName
     * @return array ['jwt' => string, 'jti' => string, 'expires_at' => Carbon]
     */
    public function generate(User $user, string $platform, string $deviceName): array
    {
        $jti = Str::uuid()->toString();
        $ttl = 120; // 2 hours in minutes

        $customClaims = [
            'jti' => $jti,
            'platform' => $platform,
            'device_name' => $deviceName,
        ];

        JWTAuth::factory()->setTTL($ttl);
        $token = JWTAuth::customClaims($customClaims)->fromUser($user);

        return [
            'jwt' => $token,
            'jti' => $jti,
            'expires_at' => Carbon::now()->addMinutes($ttl),
        ];
    }

    /**
     * Parse a JWT token and extract claims.
     *
     * @param string $token
     * @return array ['user_id' => int, 'platform' => string, 'jti' => string, 'device_name' => string]
     * @throws \Exception
     */
    public function parse(string $token): array
    {
        try {
            $payload = JWTAuth::setToken($token)->getPayload();

            return [
                'user_id' => $payload->get('sub'),
                'platform' => $payload->get('platform'),
                'jti' => $payload->get('jti'),
                'device_name' => $payload->get('device_name'),
            ];
        } catch (\Exception $e) {
            Log::channel('auth')->error('Failed to parse JWT token', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Invalid token');
        }
    }

    /**
     * Verify token signature and expiry.
     *
     * @param string $token
     * @return bool
     */
    public function verify(string $token): bool
    {
        try {
            return JWTAuth::setToken($token)->check();
        } catch (\Exception $e) {
            Log::channel('auth')->warning('Token verification failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Add a JWT ID to the blacklist in Redis.
     *
     * @param string $jti
     * @param int $ttl
     * @return void
     */
    public function blacklist(string $jti, int $ttl): void
    {
        try {
            $key = "jwt_blacklist:{$jti}";
            Redis::setex($key, $ttl, '1');
        } catch (\Exception $e) {
            Log::channel('auth')->warning('Failed to blacklist JWT', [
                'jti' => $jti,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if a JWT ID is blacklisted.
     *
     * @param string $jti
     * @return bool
     */
    public function isBlacklisted(string $jti): bool
    {
        try {
            $key = "jwt_blacklist:{$jti}";
            return Redis::exists($key) > 0;
        } catch (\Exception $e) {
            Log::channel('auth')->warning('Failed to check JWT blacklist', [
                'jti' => $jti,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
