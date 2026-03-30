<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\V1\Product\CategoryController;
use App\Http\Controllers\Api\V1\Product\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', [HealthController::class, 'ping']);

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

Route::prefix('auth')->group(function () {
    Route::middleware(['platform.identify'])->group(function () {
        Route::post('/sms/send', [AuthController::class, 'sendSmsCode'])
            ->middleware('throttle:5,1');

        Route::post('/login/sms', [AuthController::class, 'loginBySms'])
            ->middleware('throttle:10,1');

        Route::post('/login/password', [AuthController::class, 'loginByPassword'])
            ->middleware('throttle:5,1');

        Route::post('/login/wechat', [AuthController::class, 'loginByWeChat'])
            ->middleware('throttle:10,1');

        Route::post('/login/alipay', [AuthController::class, 'loginByAlipay'])
            ->middleware('throttle:10,1');

        Route::post('/refresh', [TokenController::class, 'refresh']);
    });

    Route::middleware(['jwt.auth', 'jwt.blacklist', 'db.check'])->group(function () {
        Route::post('/logout', [TokenController::class, 'logout']);

        Route::get('/devices', [DeviceController::class, 'index']);
        Route::delete('/devices/{platform}', [DeviceController::class, 'destroy']);
    });
});
