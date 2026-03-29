<?php

namespace App\Services\Auth;

interface SocialAuthProviderInterface
{
    /**
     * Get user information from the social platform using the authorization code.
     *
     * @param string $code The authorization code from the client
     * @return array Returns an array with keys: platform_id, union_id (optional), nickname, avatar, access_token, refresh_token (optional), expires_in (optional)
     * @throws \App\Exceptions\Auth\SocialAuthException
     */
    public function getUserInfo(string $code): array;
}
