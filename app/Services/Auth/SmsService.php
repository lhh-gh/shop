<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\SmsSendTooFrequentException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SmsService
{
    /**
     * Rate limit config: [key_suffix => [limit, ttl_seconds]]
     */
    private const RATE_LIMITS = [
        'min'  => [1, 60],      // 1 per minute
        'hour' => [5, 3600],    // 5 per hour
        'day'  => [10, 86400],  // 10 per day
    ];

    private const CODE_TTL = 300; // 5 minutes
    private const CODE_LENGTH = 6;

    /**
     * Send SMS verification code.
     *
     * @param string $phone
     * @return bool
     * @throws SmsSendTooFrequentException
     */
    public function send(string $phone): bool
    {
        // Check rate limits
        $this->checkRateLimits($phone);

        // Generate code
        $code = $this->generateCode();

        // Store code in Redis
        Redis::setex("sms_code:{$phone}", self::CODE_TTL, $code);

        // Update rate limit counters
        $this->updateRateLimitCounters($phone);

        // TODO: Call actual SMS gateway here (Aliyun SMS / Tencent SMS)
        // For now, log the code in dev environment
        Log::channel('security')->info('SMS code sent', [
            'phone' => substr($phone, 0, 3) . '****' . substr($phone, -4),
            'code'  => app()->isLocal() ? $code : '******',
        ]);

        return true;
    }

    /**
     * Verify SMS code.
     *
     * @param string $phone
     * @param string $code
     * @return bool
     */
    public function verify(string $phone, string $code): bool
    {
        $storedCode = Redis::get("sms_code:{$phone}");

        if (!$storedCode || $storedCode !== $code) {
            return false;
        }

        // Delete code after successful verification (single use)
        Redis::del("sms_code:{$phone}");

        return true;
    }

    /**
     * Check all rate limits for a phone number.
     *
     * @throws SmsSendTooFrequentException
     */
    private function checkRateLimits(string $phone): void
    {
        foreach (self::RATE_LIMITS as $suffix => [$limit, $ttl]) {
            $key = "sms_rate:{$suffix}:{$phone}";
            $count = Redis::get($key);

            if ($count !== null && (int) $count >= $limit) {
                Log::channel('security')->warning('SMS rate limit hit', [
                    'phone'  => $phone,
                    'window' => $suffix,
                    'count'  => $count,
                ]);
                throw new SmsSendTooFrequentException();
            }
        }
    }

    /**
     * Update rate limit counters after sending.
     */
    private function updateRateLimitCounters(string $phone): void
    {
        // Minute: simple set with TTL
        Redis::setex("sms_rate:min:{$phone}", 60, 1);

        // Hour and day: increment with TTL on first set
        foreach (['hour' => 3600, 'day' => 86400] as $suffix => $ttl) {
            $key = "sms_rate:{$suffix}:{$phone}";
            $newCount = Redis::incr($key);
            if ($newCount === 1) {
                Redis::expire($key, $ttl);
            }
        }
    }

    /**
     * Generate a random numeric code.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}
