<?php

namespace App\Http\Middleware;

use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * JWT 黑名单检查中间件
 *
 * 职责：
 * 检查当前 JWT 的 jti 是否在 Redis 黑名单中。
 * 黑名单中的 Token 意味着：
 * - 用户在其他设备登录，本设备被踢下线（同平台互踢）
 * - 用户主动在安全中心下线了该设备
 * - 管理员封禁了该账号
 *
 * 错误码：
 * - 40104: 设备被踢下线
 */
class JwtBlacklist
{
    public function __construct(
        private readonly JwtService $jwtService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $jti = $request->attributes->get('jwt_jti');

        if (!$jti) {
            // 如果没有 jti，说明 JwtAuthenticate 中间件未先执行
            return response()->json([
                'code'    => 40100,
                'message' => '认证信息不完整',
                'data'    => null,
            ], 401);
        }

        // 查询 Redis 黑名单
        try {
            if ($this->jwtService->isBlacklisted($jti)) {
                return response()->json([
                    'code'    => 40104,
                    'message' => '您的账号已在另一台设备登录',
                    'data'    => null,
                ], 401);
            }
        } catch (\Exception $e) {
            // Redis 异常时优雅降级：放行请求，记录告警
            Log::channel('security')->warning('JWT黑名单检查失败（Redis异常），默认放行', [
                'jti'   => $jti,
                'error' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }
}
