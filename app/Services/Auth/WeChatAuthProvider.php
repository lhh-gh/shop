<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use App\Exceptions\Auth\SocialAuthException;

class WeChatAuthProvider implements SocialAuthProviderInterface
{
    public function getUserInfo(string $code): array
    {
        // Placeholder for actual WeChat OAuth logic.
        // In a real scenario, this would call api.weixin.qq.com with app_id and app_secret
        // to exchange the code for an access token and user info.
        
        // Mock response for development until real credentials are provided
        if ($code === 'invalid_code') {
            throw new SocialAuthException('WeChat authorization failed.');
        }

        return [
            'platform_id'   => 'wx_openid_' . bin2hex(random_bytes(8)),
            'union_id'      => 'wx_unionid_' . bin2hex(random_bytes(8)),
            'nickname'      => 'WeChat User',
            'avatar'        => 'https://example.com/default-avatar.png',
            'access_token'  => 'mock_wechat_access_token',
            'refresh_token' => 'mock_wechat_refresh_token',
            'expires_in'    => 7200,
        ];
    }
}
