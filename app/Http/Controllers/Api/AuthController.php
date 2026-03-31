<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginByPasswordRequest;
use App\Http\Requests\Auth\LoginBySmsRequest;
use App\Http\Requests\Auth\LoginBySocialRequest;
use App\Http\Requests\Auth\LoginByWeChatRequest;
use App\Http\Requests\Auth\SendSmsCodeRequest;
use App\Services\Auth\AuthService;
use App\Services\Auth\SmsService;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly SmsService $smsService,
    ) {}

    public function sendSmsCode(SendSmsCodeRequest $request): JsonResponse
    {
        $this->smsService->send($request->validated('phone'));

        return $this->success(null, '验证码发送成功');
    }

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

    public function loginByWeChat(LoginByWeChatRequest $request): JsonResponse
    {
        $platform = $request->attributes->get('platform', $request->header('X-Platform', 'h5'));

        $result = $this->authService->loginBySocial(
            $platform === 'mini_program' ? 'wechat_mini' : 'wechat_app',
            $request->validated('code'),
            $platform,
            $request,
        );

        return $this->success($result, '登录成功');
    }

    public function loginByAlipay(LoginBySocialRequest $request): JsonResponse
    {
        $platform = $request->attributes->get('platform', $request->header('X-Platform', 'h5'));

        $result = $this->authService->loginBySocial(
            'alipay',
            $request->validated('code'),
            $platform,
            $request,
        );

        return $this->success($result, '登录成功');
    }
}
