<?php

namespace App\Http\Middleware;

use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * JWT 认证中间件
 *
 * 职责：
 * 1. 从 Authorization: Bearer {jwt} 头部提取 Token
 * 2. 验证 JWT 签名（HMAC-SHA256）
 * 3. 检查 Token 是否过期
 * 4. 提取 payload 中的 sub(user_id)、jti、platform 注入 Request
 *
 * 错误码：
 * - 40101: Token 已过期 → 客户端应发起刷新
 * - 40100: Token 无效（签名错误/格式错误）
 */
class JwtAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return $this->unauthorized(40100, '未提供认证凭证');
        }

        try {
            // 设置 Token 并获取 payload
            $payload = JWTAuth::setToken($token)->getPayload();

            // 注入用户信息到 Request，供后续中间件和控制器使用
            $request->attributes->set('jwt_user_id', $payload->get('sub'));
            $request->attributes->set('jwt_jti', $payload->get('jti'));
            $request->attributes->set('jwt_platform', $payload->get('platform'));
            $request->attributes->set('jwt_token', $token);

        } catch (TokenExpiredException $e) {
            // Token 已过期 → 客户端应使用 Refresh Token 刷新
            return $this->unauthorized(40101, 'Token已过期，请刷新');

        } catch (TokenInvalidException $e) {
            // Token 签名无效或格式错误
            return $this->unauthorized(40100, 'Token无效');

        } catch (\Exception $e) {
            Log::channel('security')->error('JWT解析异常', [
                'error' => $e->getMessage(),
                'ip'    => $request->ip(),
            ]);
            return $this->unauthorized(40100, '认证失败');
        }

        return $next($request);
    }

    /**
     * 从 Authorization 请求头提取 Bearer Token
     */
    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }

    /**
     * 返回 401 错误响应
     */
    private function unauthorized(int $code, string $message): Response
    {
        return response()->json([
            'code'    => $code,
            'message' => $message,
            'data'    => null,
        ], 401);
    }
}
