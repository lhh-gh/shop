<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestLog
{
    /**
     * 排除日志记录的路由
     */
    protected array $except = [
        'api/health',
        'api/v1/ping',
        'up',
    ];

    /**
     * 敏感字段脱敏
     */
    protected array $sensitiveFields = [
        'password',
        'password_confirmation',
        'sms_code',
        'code',
        'refresh_token',
        'access_token',
        'token',
        'id_card',
        'bank_card',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...$this->except)) {
            return $next($request);
        }

        // 生成请求唯一 ID（全链路追踪）
        $requestId = $request->header('X-Request-Id') ?: Str::uuid()->toString();
        $request->headers->set('X-Request-Id', $requestId);

        $startTime = microtime(true);

        $response = $next($request);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::channel('request')->info('API Request', [
            'request_id' => $requestId,
            'method'     => $request->method(),
            'url'        => $request->fullUrl(),
            'ip'         => $request->ip(),
            'user_agent' => Str::limit($request->userAgent(), 200),
            'user_id'    => auth()->id(),
            'platform'   => $request->header('X-Platform', 'unknown'),
            'input'      => $this->filterSensitive($request->all()),
            'status'     => $response->getStatusCode(),
            'duration'   => $duration . 'ms',
            'response_size' => strlen($response->getContent()),
        ]);

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Response-Time', $duration . 'ms');

        // 慢请求告警（>3s）
        if ($duration > 3000) {
            Log::channel('error')->warning('Slow Request', [
                'request_id' => $requestId,
                'url'        => $request->fullUrl(),
                'duration'   => $duration . 'ms',
                'user_id'    => auth()->id(),
            ]);
        }

        return $response;
    }

    protected function filterSensitive(array $data): array
    {
        foreach ($this->sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '***';
            }
        }
        return $data;
    }
}
