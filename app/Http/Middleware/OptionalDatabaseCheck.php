<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 可选数据库用户状态检查中间件
 *
 * 职责：
 * 从 MySQL 查询用户 status 字段，检查账号是否被禁用。
 * JWT 自包含 user_id，但无法实时反映用户状态变更（如管理员封禁），
 * 因此需要此中间件补充查库检查。
 *
 * 优雅降级：
 * 如果 MySQL 查询失败（数据库宕机等），跳过检查并记录告警日志，
 * 信任 JWT 中的数据继续放行，保证服务可用性。
 *
 * 错误码：
 * - 40105: 账号已被禁用
 */
class OptionalDatabaseCheck
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->attributes->get('jwt_user_id');

        if (!$userId) {
            return $next($request);
        }

        try {
            $user = User::select('id', 'status')->find($userId);

            if ($user && $user->status === 0) {
                return response()->json([
                    'code'    => 40105,
                    'message' => '您的账号已被禁用',
                    'data'    => null,
                ], 401);
            }

            // 将用户实例注入 Request，后续控制器可直接使用
            if ($user) {
                $request->attributes->set('auth_user', $user);
            }

        } catch (\Exception $e) {
            // 数据库异常：跳过检查，记录告警，信任 JWT 数据
            Log::channel('security')->warning('用户状态查库失败（数据库异常），跳过检查', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
                'ip'      => $request->ip(),
            ]);
        }

        return $next($request);
    }
}
