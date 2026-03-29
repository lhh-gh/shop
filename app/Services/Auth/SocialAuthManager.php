<?php

namespace App\Services\Auth;

use Illuminate\Support\Manager;
use InvalidArgumentException;

class SocialAuthManager extends Manager
{
    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        throw new InvalidArgumentException('No Social Auth driver was specified.');
    }

    /**
     * Create an instance of the WeChat driver.
     *
     * @return SocialAuthProviderInterface
     */
    protected function createWechatDriver(): SocialAuthProviderInterface
    {
        return new WeChatAuthProvider();
    }

    /**
     * Create an instance of the Alipay driver.
     *
     * @return SocialAuthProviderInterface
     */
    protected function createAlipayDriver(): SocialAuthProviderInterface
    {
        return new AlipayAuthProvider();
    }
}
