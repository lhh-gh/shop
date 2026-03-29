<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginByPasswordRequest;
use App\Http\Requests\Auth\LoginBySmsRequest;
use App\Http\Requests\Auth\SendSmsCodeRequest;
use App\Services\Auth\AuthService;
use App\Services\Auth\SmsService;
use Illuminate\Http\JsonResponse;

/**
 * 认证控制器
 *
 * 处理用户登录与短信验证码发送。
 * 公开接口，无需 JWT 认证。
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly SmsService $smsService,
    ) {}

    /**
     * 发送短信验证码
     *
     * POST /auth/sms/send
     * Headers: X-Platform: app|mini_program|h5|pc
     * Body: { phone: "13800138000" }
     */
    public function sendSmsCode(SendSmsCodeRequest $request): JsonResponse
    {
        $this->smsService->send($request->validated('phone'));

        return $this->success(null, '验证码发送成功');
    }

    /**
     * 短信验证码登录
     *
     * POST /auth/login/sms
     * Headers: X-Platform: app
     * Body: { phone: "13800138000", code: "123456" }
     *
     * 首次登录自动注册用户。
     */
    public function loginBySms(LoginBySmsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $platform = $request->attributes->get('platform', $request->header('X-Platform', 'h5'));

        $result = $this->authService->loginBySms(
            $data['phone'],
            $data['code'],
            $platform,
            $request,
        );

        return $this->success($result, '登录成功');
    }

    /**
     * 账号密码登录
     *
     * POST /auth/login/password
     * Headers: X-Platform: pc
     * Body: { account: "13800138000", password: "123456" }
     *
     * account 支持手机号或邮箱。
     */
    public function loginByPassword(LoginByPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $platform = $request->attributes->get('platform', $request->header('X-Platform', 'h5'));

        $result = $this->authService->loginByPassword(
            $data['account'],
            $data['password'],
            $platform,
            $request,
        );

        return $this->success($result, '登录成功');
    }
}
