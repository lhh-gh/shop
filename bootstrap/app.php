<?php

use App\Exceptions\BusinessException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ──── 全局 API 中间件 ────
        $middleware->api(append: [
            \App\Http\Middleware\RequestLog::class,
        ]);

        // ──── 中间件别名注册 ────
        $middleware->alias([
            'platform.identify' => \App\Http\Middleware\PlatformIdentify::class,
            'jwt.auth'          => \App\Http\Middleware\JwtAuthenticate::class,
            'jwt.blacklist'     => \App\Http\Middleware\JwtBlacklist::class,
            'db.check'          => \App\Http\Middleware\OptionalDatabaseCheck::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // ──── 业务异常：结构化 JSON 响应 ────
        $exceptions->renderable(function (BusinessException $e, $request) {
            $response = [
                'code'    => $e->getErrorCode(),
                'message' => $e->getUserMessage(),
                'data'    => $e->getData() ?: null,
            ];

            if (app()->isLocal()) {
                $response['debug'] = [
                    'exception' => get_class($e),
                    'internal_message' => $e->getMessage(),
                    'file'  => $e->getFile() . ':' . $e->getLine(),
                    'trace' => collect($e->getTrace())->take(5)->toArray(),
                ];
            }

            return response()->json($response, $e->getHttpStatus());
        });

        // ──── Laravel 验证异常：转为统一格式 ────
        $exceptions->renderable(function (ValidationException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }
            return response()->json([
                'code'    => 49900,
                'message' => '参数验证失败',
                'data'    => [
                    'errors' => $e->errors(),
                ],
            ], 422);
        });

        // ──── Laravel 模型未找到 ────
        $exceptions->renderable(function (ModelNotFoundException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }
            $model = class_basename($e->getModel());
            return response()->json([
                'code'    => 49901,
                'message' => "{$model}不存在",
                'data'    => null,
            ], 404);
        });

        // ──── Laravel 认证异常 ────
        $exceptions->renderable(function (AuthenticationException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }
            return response()->json([
                'code'    => 49902,
                'message' => '请先登录',
                'data'    => null,
            ], 401);
        });

        // ──── Laravel 限流异常 ────
        $exceptions->renderable(function (ThrottleRequestsException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }
            return response()->json([
                'code'    => 49903,
                'message' => '请求过于频繁，请稍后再试',
                'data'    => ['retry_after' => $e->getHeaders()['Retry-After'] ?? 60],
            ], 429);
        });

        // ──── 兜底：未知异常 ────
        $exceptions->renderable(function (\Throwable $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $response = [
                'code'    => 50000,
                'message' => '服务器内部错误',
                'data'    => null,
            ];

            if (app()->isLocal()) {
                $response['debug'] = [
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile() . ':' . $e->getLine(),
                    'trace'     => collect($e->getTrace())->take(10)->toArray(),
                ];
            }

            return response()->json($response, 500);
        });

        // ──── 异常上报（日志记录） ────
        $exceptions->reportable(function (BusinessException $e) {
            Log::channel('business')->warning($e->getMessage(), [
                'error_code'  => $e->getErrorCode(),
                'http_status' => $e->getHttpStatus(),
                'user_id'     => auth()->id(),
                'url'         => request()->fullUrl(),
                'ip'          => request()->ip(),
            ]);
            return false; // 阻止 Laravel 默认上报
        });

        $exceptions->reportable(function (\Throwable $e) {
            Log::channel('error')->error($e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile() . ':' . $e->getLine(),
                'trace'     => $e->getTraceAsString(),
                'user_id'   => auth()->id(),
                'url'       => request()->fullUrl(),
                'input'     => request()->except(['password', 'password_confirmation']),
                'ip'        => request()->ip(),
            ]);
        });
    })->create();
