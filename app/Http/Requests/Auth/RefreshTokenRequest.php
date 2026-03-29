<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 刷新 Token 请求验证
 *
 * POST /auth/refresh
 */
class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string', 'size:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'refresh_token.required' => 'Refresh Token 不能为空',
            'refresh_token.size'     => 'Refresh Token 格式不正确',
        ];
    }
}
