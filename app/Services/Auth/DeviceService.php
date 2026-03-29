<?php

namespace App\Services\Auth;

use App\Repositories\SecurityLogRepository;
use App\Repositories\UserTokenRepository;
use App\Support\DataMasker;
use Illuminate\Support\Facades\Log;

class DeviceService
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly UserTokenRepository $tokenRepository,
        private readonly SecurityLogRepository $securityLogRepository
    ) {
    }

    /**
     * Get all active devices for a user.
     *
     * @param int $userId
     * @return array
     */
    public function list(int $userId): array
    {
        $tokens = $this->tokenRepository->findActiveByUser($userId);

        if ($tokens->isEmpty()) {
            return [];
        }

        $currentPlatform = request()->header('X-Platform', 'unknown');

        return $tokens->map(function ($token) use ($currentPlatform) {
            return [
                'platform' => $token->platform,
                'device_name' => $token->device_name,
                'client_ip' => DataMasker::ip($token->client_ip),
                'last_active_at' => $token->last_active_at->toIso8601String(),
                'expires_at' => $token->expires_at->toIso8601String(),
                'is_current' => $token->platform === $currentPlatform,
            ];
        })->toArray();
    }

    /**
     * Kick a specific device by platform.
     *
     * @param int $userId
     * @param string $platform
     * @return void
     */
    public function kick(int $userId, string $platform): void
    {
        $tokens = $this->tokenRepository->findActiveByUser($userId);
        $token = $tokens->firstWhere('platform', $platform);

        if (!$token) {
            return;
        }

        // Calculate TTL in seconds until token expires
        $ttl = max(1, $token->expires_at->diffInSeconds(now()));

        // Blacklist the JWT
        $this->jwtService->blacklist($token->last_jwt_jti, $ttl);

        // Delete the refresh token
        $this->tokenRepository->deleteByUserAndPlatform($userId, $platform);

        // Log security event
        $this->logSecurityEvent($userId, 'device_kicked', [
            'platform' => $platform,
        ]);

        Log::channel('auth')->info('Device kicked', [
            'user_id' => $userId,
            'platform' => $platform,
        ]);
    }

    /**
     * Kick all devices except optionally the current one.
     *
     * @param int $userId
     * @param string|null $exceptPlatform
     * @return void
     */
    public function kickAll(int $userId, ?string $exceptPlatform = null): void
    {
        $tokens = $this->tokenRepository->findActiveByUser($userId);

        if ($tokens->isEmpty()) {
            return;
        }

        foreach ($tokens as $token) {
            // Skip the current platform if specified
            if ($exceptPlatform && $token->platform === $exceptPlatform) {
                continue;
            }

            // Calculate TTL in seconds until token expires
            $ttl = max(1, $token->expires_at->diffInSeconds(now()));

            // Blacklist the JWT
            $this->jwtService->blacklist($token->last_jwt_jti, $ttl);

            // Delete the refresh token
            $this->tokenRepository->deleteByUserAndPlatform($userId, $token->platform);

            // Log security event
            $this->logSecurityEvent($userId, 'device_kicked', [
                'platform' => $token->platform,
            ]);
        }

        Log::channel('auth')->info('All devices kicked', [
            'user_id' => $userId,
            'except_platform' => $exceptPlatform,
            'count' => $tokens->count(),
        ]);
    }

    /**
     * Log a security event.
     *
     * @param int $userId
     * @param string $event
     * @param array $context
     * @return void
     */
    private function logSecurityEvent(int $userId, string $event, array $context = []): void
    {
        $this->securityLogRepository->create([
            'user_id' => $userId,
            'event' => $event,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'context' => $context,
        ]);
    }
}

