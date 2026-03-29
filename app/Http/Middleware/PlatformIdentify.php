<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 平台识别中间件
 *
 * 从请求头 X-Platform 提取客户端平台标识，
 * 验证其为合法平台值（app/mini_program/h5/pc），
 * 并注入到 request attributes 中供后续使用。
 */
class PlatformIdentify
{
    /**
     * 允许的平台标识列表
     */
    private const VALID_PLATFORMS = ['app', 'mini_program', 'h5', 'pc'];

    public function handle(Request $request, Closure $next): Response
    {
        $platform = $request->header('X-Platform');

        if (!$platform || !in_array($platform, self::VALID_PLATFORMS, true)) {
            return response()->json([
                'code'    => 40001,
                'message' => '缺少平台标识或平台标识无效，请在请求头中传递 X-Platform',
                'data'    => [
                    'valid_platforms' => self::VALID_PLATFORMS,
                ],
            ], 400);
        }

        // 注入到 request attributes，后续可通过 $request->attributes->get('platform') 获取
        $request->attributes->set('platform', $platform);

        return $next($request);
    }
}
