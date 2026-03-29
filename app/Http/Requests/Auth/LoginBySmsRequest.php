<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 短信验证码登录请求验证
 *
 * POST /auth/login/sms
 */
class LoginBySmsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'code'  => ['required', 'string', 'size:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => '手机号不能为空',
            'phone.regex'    => '手机号格式不正确',
            'code.required'  => '验证码不能为空',
            'code.size'      => '验证码为6位数字',
        ];
    }
}
