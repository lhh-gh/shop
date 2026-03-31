<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\RefreshTokenExpiredException;
use App\Exceptions\Auth\SmsCodeInvalidException;
use App\Exceptions\Auth\TokenLeakException;
use App\Exceptions\Auth\UserDisabledException;
use App\Models\User;
use App\Repositories\SecurityLogRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserTokenRepository;
use App\Models\UserSocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthService
{
    use LogsSecurityEvent;

    public function __construct(
        private readonly JwtService $jwtService,
        private readonly SmsService $smsService,
        private readonly UserRepository $userRepository,
        private readonly UserTokenRepository $tokenRepository,
        private readonly SecurityLogRepository $securityLogRepository,
        private readonly SocialAuthManager $socialAuthManager,
    ) {}

    /**
     * Login by SMS verification code.
     * Auto-registers if user doesn't exist.
     */
    public function loginBySms(string $phone, string $code, string $platform, Request $request): array
    {
        // 1. Verify SMS code
        if (!$this->smsService->verify($phone, $code)) {
            throw new SmsCodeInvalidException();
        }

        // 2. Find or create user
        $user = $this->userRepository->findByPhone($phone);
        if (!$user) {
            $nickname = '用户' . substr($phone, -4);
            $user = $this->userRepository->create([
                'phone'    => $phone,
                'name'     => $nickname,
                'nickname' => $nickname,
                'status'   => 1,
            ]);
        }

        // 3. Check user status
        $this->ensureUserActive($user);

        // 4. Kick same-platform devices & issue tokens
        return $this->completeLogin($user, $platform, $request, 'sms_login');
    }

    /**
     * Login by Social Provider (WeChat, Alipay).
     */
    public function loginBySocial(string $provider, string $code, string $platform, Request $request): array
    {
        // 1. Get user info from social provider
        $driver = $this->socialAuthManager->driver($provider);
        $socialUser = $driver->getUserInfo($code);

        // 2. Find existing bound account or create new user + binding
        $user = DB::transaction(function () use ($provider, $socialUser) {
            $account = UserSocialAccount::where('platform', $provider)
                ->where('platform_id', $socialUser['platform_id'])
                ->first();

            if ($account) {
                // Update tokens if they changed
                $account->update([
                    'nickname'      => $socialUser['nickname'] ?? $account->nickname,
                    'avatar'        => $socialUser['avatar'] ?? $account->avatar,
                    'access_token'  => $socialUser['access_token'] ?? $account->access_token,
                    'refresh_token' => $socialUser['refresh_token'] ?? $account->refresh_token,
                    'expires_at'    => isset($socialUser['expires_in']) ? now()->addSeconds($socialUser['expires_in']) : $account->expires_at,
                ]);
                return $account->user;
            }

            // Create new user if no account bound (could be expanded to bind existing user if phone is provided)
            $user = $this->userRepository->create([
                'name'     => $socialUser['nickname'],
                'nickname' => $socialUser['nickname'],
                'avatar'   => $socialUser['avatar'],
                'status'   => 1,
            ]);

            // Create social account binding
            $user->socialAccounts()->create([
                'platform'      => $provider,
                'platform_id'   => $socialUser['platform_id'],
                'union_id'      => $socialUser['union_id'] ?? null,
                'nickname'      => $socialUser['nickname'],
                'avatar'        => $socialUser['avatar'],
                'access_token'  => $socialUser['access_token'] ?? null,
                'refresh_token' => $socialUser['refresh_token'] ?? null,
                'expires_at'    => isset($socialUser['expires_in']) ? now()->addSeconds($socialUser['expires_in']) : null,
            ]);

            return $user;
        });

        // 3. Check user status
        $this->ensureUserActive($user);

        // 4. Kick same-platform devices & issue tokens
        return $this->completeLogin($user, $platform, $request, "{$provider}_login");
    }

    /**
     * Login by phone/email + password.
     */
    public function loginByPassword(string $account, string $password, string $platform, Request $request): array
    {
        // 1. Find user by phone or email
        $user = filter_var($account, FILTER_VALIDATE_EMAIL)
            ? $this->userRepository->findByEmail($account)
            : $this->userRepository->findByPhone($account);

        if (!$user || !$user->password) {
            throw new InvalidCredentialsException();
        }

        // 2. Verify password
        if (!Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException();
        }

        // 3. Check user status
        $this->ensureUserActive($user);

        // 4. Kick same-platform devices & issue tokens
        return $this->completeLogin($user, $platform, $request, 'password_login');
    }

    /**
     * Refresh tokens using refresh token.
     * Implements token rotation and leak detection.
     */
    public function refresh(string $rawRefreshToken, string $platform, Request $request): array
    {
        // 1. Hash and lookup
        $tokenHash = hash('sha256', $rawRefreshToken);
        $tokenRecord = $this->tokenRepository->findByToken($tokenHash);

        if (!$tokenRecord || $tokenRecord->expires_at->isPast()) {
            throw new RefreshTokenExpiredException();
        }

        $storedPlatform = $tokenRecord->platform;

        if ($platform !== $storedPlatform) {
            Log::channel('security')->warning('Refresh token platform mismatch', [
                'user_id' => $tokenRecord->user_id,
                'stored_platform' => $storedPlatform,
                'request_platform' => $platform,
                'ip' => $request->ip(),
            ]);

            $this->jwtService->blacklist($tokenRecord->last_jwt_jti, 7200);
            $this->tokenRepository->deleteByUserAndPlatform($tokenRecord->user_id, $storedPlatform);

            $this->securityLogRepository->create([
                'user_id' => $tokenRecord->user_id,
                'event' => 'token_leak',
                'detail' => [
                    'reason' => 'platform_mismatch',
                    'stored_platform' => $storedPlatform,
                    'request_platform' => $platform,
                ],
                'ip' => $request->ip(),
                'user_agent' => Str::limit($request->userAgent() ?? '', 500),
            ]);

            throw new TokenLeakException($tokenRecord->user_id, $storedPlatform);
        }

        // 2. Leak detection
        $this->detectLeak($tokenRecord, $request, $storedPlatform);

        // 3. Load user
        $user = $tokenRecord->user;
        $this->ensureUserActive($user);

        // 4. Blacklist old JWT (short TTL, just remaining access token life)
        $this->jwtService->blacklist($tokenRecord->last_jwt_jti, 7200);

        // 5. Generate new JWT
        $deviceName = $tokenRecord->device_name ?? $this->parseDeviceName($request);
        $jwtData = $this->jwtService->generate($user, $storedPlatform, $deviceName);

        // 6. Rotate refresh token
        $newRawToken = Str::random(64);
        $tokenRecord->update([
            'token'          => hash('sha256', $newRawToken),
            'last_jwt_jti'   => $jwtData['jti'],
            'client_ip'      => $request->ip(),
            'user_agent'     => Str::limit($request->userAgent() ?? '', 500),
            'last_active_at' => now(),
        ]);

        $this->logSecurityEvent('token_refresh', [
            'user_id'  => $user->id,
            'platform' => $storedPlatform,
        ]);

        return $this->formatTokenResponse($user, $jwtData['jwt'], $newRawToken);
    }

    /**
     * Logout: blacklist JWT + delete refresh token.
     */
    public function logout(int $userId, string $platform): void
    {
        $tokens = $this->tokenRepository->findActiveByUser($userId);
        $token = $tokens->firstWhere('platform', $platform);

        if ($token) {
            $this->jwtService->blacklist($token->last_jwt_jti, 7200);
            $this->tokenRepository->deleteByUserAndPlatform($userId, $platform);
        }

        $this->logSecurityEvent('logout', [
            'user_id'  => $userId,
            'platform' => $platform,
        ]);
    }

    /**
     * Complete login flow: kick same-platform → issue tokens → log.
     */
    private function completeLogin(User $user, string $platform, Request $request, string $loginMethod): array
    {
        // 1. Kick same-platform devices
        $this->kickSamePlatform($user->id, $platform);

        // 2. Issue new tokens
        $deviceName = $this->parseDeviceName($request);
        $jwtData = $this->jwtService->generate($user, $platform, $deviceName);

        // 3. Create refresh token
        $rawRefreshToken = Str::random(64);
        $this->tokenRepository->create([
            'user_id'        => $user->id,
            'platform'       => $platform,
            'token'          => hash('sha256', $rawRefreshToken),
            'last_jwt_jti'   => $jwtData['jti'],
            'device_name'    => $deviceName,
            'client_ip'      => $request->ip(),
            'user_agent'     => Str::limit($request->userAgent() ?? '', 500),
            'last_active_at' => now(),
            'expires_at'     => now()->addDays(30),
        ]);

        // 4. Log security event
        $this->securityLogRepository->create([
            'user_id'    => $user->id,
            'event'      => $loginMethod,
            'detail'     => [
                'platform'    => $platform,
                'device_name' => $deviceName,
                'ip'          => $request->ip(),
            ],
            'ip'         => $request->ip(),
            'user_agent' => Str::limit($request->userAgent() ?? '', 500),
        ]);

        $this->logSecurityEvent($loginMethod, [
            'user_id'  => $user->id,
            'platform' => $platform,
        ]);

        return $this->formatTokenResponse($user, $jwtData['jwt'], $rawRefreshToken);
    }

    /**
     * Kick same-platform old devices.
     */
    private function kickSamePlatform(int $userId, string $platform): void
    {
        $oldTokens = $this->tokenRepository->findActiveByUser($userId)
            ->where('platform', $platform);

        foreach ($oldTokens as $oldToken) {
            // Blacklist old JWT
            if ($oldToken->last_jwt_jti) {
                $this->jwtService->blacklist($oldToken->last_jwt_jti, 7200);
            }
        }

        // Delete all same-platform refresh tokens
        $this->tokenRepository->deleteByUserAndPlatform($userId, $platform);
    }

    /**
     * Detect potential token leak by comparing IP and User-Agent fingerprints.
     * PC: IP OR UA mismatch → leak
     * Mobile: IP AND UA mismatch → leak
     */
    private function detectLeak($tokenRecord, Request $request, string $platform): void
    {
        $currentIp = $request->ip();
        $currentUa = $request->userAgent() ?? '';
        $storedIp = $tokenRecord->client_ip;
        $storedUa = $tokenRecord->user_agent;

        $ipChanged = $currentIp !== $storedIp;
        $uaChanged = $this->uaFingerprint($currentUa) !== $this->uaFingerprint($storedUa ?? '');

        $isLeak = match ($platform) {
            'pc'   => $ipChanged || $uaChanged,   // PC: strict
            default => $ipChanged && $uaChanged,   // Mobile: lenient
        };

        if ($isLeak) {
            // Log the potential leak
            Log::channel('security')->critical('Token leak detected', [
                'user_id'    => $tokenRecord->user_id,
                'platform'   => $platform,
                'stored_ip'  => $storedIp,
                'current_ip' => $currentIp,
                'ua_changed' => $uaChanged,
            ]);

            // Invalidate all tokens for this user on this platform
            $this->jwtService->blacklist($tokenRecord->last_jwt_jti, 7200);
            $this->tokenRepository->deleteByUserAndPlatform($tokenRecord->user_id, $platform);

            // Log security event
            $this->securityLogRepository->create([
                'user_id'    => $tokenRecord->user_id,
                'event'      => 'token_leak',
                'detail'     => [
                    'platform'   => $platform,
                    'stored_ip'  => $storedIp,
                    'current_ip' => $currentIp,
                ],
                'ip'         => $currentIp,
                'user_agent' => $currentUa,
            ]);

            throw new TokenLeakException($tokenRecord->user_id, $platform);
        }
    }

    /**
     * Extract a simplified UA fingerprint for comparison.
     * We only compare browser/OS family, not full version strings.
     */
    private function uaFingerprint(string $ua): string
    {
        // Normalize: lowercase, strip version numbers
        $normalized = strtolower($ua);
        $normalized = preg_replace('/[\d.]+/', '', $normalized);
        return md5($normalized);
    }

    /**
     * Parse device name from User-Agent.
     */
    private function parseDeviceName(Request $request): string
    {
        $ua = $request->userAgent() ?? 'Unknown';
        return Str::limit($ua, 100);
    }

    /**
     * Ensure user account is active.
     *
     * @throws UserDisabledException
     */
    private function ensureUserActive(User $user): void
    {
        if ($user->status === 0) {
            throw new UserDisabledException($user->id);
        }
    }

    /**
     * Format token response data.
     */
    private function formatTokenResponse(User $user, string $accessToken, string $refreshToken): array
    {
        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => 7200, // 2 hours in seconds
            'user'          => new UserResource($user),
        ];
    }
}
