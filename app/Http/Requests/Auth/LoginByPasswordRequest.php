<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 密码登录请求验证
 *
 * POST /auth/login/password
 */
class LoginByPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account'  => ['required', 'string'],  // 手机号或邮箱
            'password' => ['required', 'string', 'min:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'account.required'  => '账号不能为空',
            'password.required' => '密码不能为空',
            'password.min'      => '密码至少6位',
        ];
    }
}
