<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use App\Exceptions\Auth\SocialAuthException;

class AlipayAuthProvider implements SocialAuthProviderInterface
{
    public function getUserInfo(string $code): array
    {
        // Placeholder for actual Alipay openapi logic.
        
        if ($code === 'invalid_code') {
            throw new SocialAuthException('Alipay authorization failed.');
        }

        return [
            'platform_id'   => 'alipay_id_' . bin2hex(random_bytes(8)),
            'union_id'      => null,
            'nickname'      => 'Alipay User',
            'avatar'        => 'https://example.com/default-avatar.png',
            'access_token'  => 'mock_alipay_access_token',
            'refresh_token' => 'mock_alipay_refresh_token',
            'expires_in'    => 3600,
        ];
    }
}
