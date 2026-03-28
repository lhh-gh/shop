<?php

use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| API 路由前缀已在 bootstrap/app.php 中配置为 api/v1
| 此处定义的路由实际地址为: /api/v1/...
|
*/

// ──── 公开接口（无需认证） ────
Route::get('/ping', fn () => ApiResponse::success(['time' => now()->toISOString()]));
