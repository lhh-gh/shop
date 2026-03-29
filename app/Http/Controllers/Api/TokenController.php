<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token 控制器
 *
 * 处理 Token 刷新和用户登出。
 * - 刷新：公开接口（使用 Refresh Token 认证，不需要 JWT）
 * - 登出：需要 JWT 认证
 */
class TokenController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * 刷新 Token
     *
     * POST /auth/refresh
     * Headers: X-Platform: app
     * Body: { refresh_token: "f8a3b7c1d9e2..." }
     *
     * Access Token 过期后，客户端使用 Refresh Token 获取新的双 Token。
     * 旧 Refresh Token 立即失效（Token 旋转）。
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $platform = $request->attributes->get('platform', $request->header('X-Platform', 'h5'));

        $result = $this->authService->refresh(
            $request->validated('refresh_token'),
            $platform,
            $request,
        );

        return $this->success($result, '刷新成功');
    }

    /**
     * 登出
     *
     * POST /auth/logout
     * Headers: Authorization: Bearer {jwt}
     *
     * 将当前 JWT 加入黑名单，删除对应 Refresh Token。
     */
    public function logout(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('jwt_user_id');
        $platform = $request->attributes->get('jwt_platform',
            $request->header('X-Platform', 'h5'));

        $this->authService->logout($userId, $platform);

        return $this->success(null, '已退出登录');
    }
}
