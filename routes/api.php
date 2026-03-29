<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API 路由
|--------------------------------------------------------------------------
|
| API 路由前缀已在 bootstrap/app.php 中配置为 api/v1
| 此处定义的路由实际地址为: /api/v1/...
|
*/

// ──── 健康检查 ────
Route::get('/ping', [HealthController::class, 'ping']);

// ──── 公共商品模块 ────
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

// ──── 认证相关路由 ────
Route::prefix('auth')->group(function () {

    // ── 公开接口（无需认证，需要平台标识） ──
    Route::middleware(['platform.identify'])->group(function () {

        // 发送短信验证码
        Route::post('/sms/send', [AuthController::class, 'sendSmsCode'])
            ->middleware('throttle:5,1'); // 每分钟最多5次

        // 短信验证码登录
        Route::post('/login/sms', [AuthController::class, 'loginBySms'])
            ->middleware('throttle:10,1');

        // 账号密码登录
        Route::post('/login/password', [AuthController::class, 'loginByPassword'])
            ->middleware('throttle:5,1');

        // Token 刷新（使用 Refresh Token，不需要 JWT）
        Route::post('/refresh', [TokenController::class, 'refresh']);
    });

    // ── 需要认证的接口 ──
    Route::middleware(['jwt.auth', 'jwt.blacklist', 'db.check'])->group(function () {

        // 登出
        Route::post('/logout', [TokenController::class, 'logout']);

        // 设备管理
        Route::get('/devices', [DeviceController::class, 'index']);
        Route::delete('/devices/{platform}', [DeviceController::class, 'destroy']);
    });
});
