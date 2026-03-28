<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    'default' => env('LOG_CHANNEL', 'daily'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        // ========== 默认通道（Laravel 框架日志） ==========
        'daily' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => 'debug',
            'days'   => 30,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        // ========== 应用层日志 ==========

        // 业务操作日志（业务异常、重要操作记录）
        'business' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/business/business.log'),
            'level'  => 'info',
            'days'   => 60,
        ],

        // 系统错误日志（未捕获异常、500 错误）
        'error' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/error/error.log'),
            'level'  => 'error',
            'days'   => 90,
        ],

        // 安全事件日志（登录、Token 刷新、泄露检测、踢设备）
        'security' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/security/security.log'),
            'level'  => 'info',
            'days'   => 180,
        ],

        // 队列任务日志
        'queue' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/queue/queue.log'),
            'level'  => 'info',
            'days'   => 30,
        ],

        // ========== 请求层日志 ==========

        // API 请求日志（请求/响应/耗时）
        'request' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/request/api.log'),
            'level'  => 'info',
            'days'   => 14,
        ],

        // 支付回调专用日志（最高优先级保留）
        'payment_callback' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/payment/callback.log'),
            'level'  => 'info',
            'days'   => 365,
        ],

        // ========== 数据层日志 ==========

        // SQL 全量日志（仅开发环境启用）
        'sql' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/sql/query.log'),
            'level'  => 'debug',
            'days'   => 7,
        ],

        // 慢查询日志（所有环境启用）
        'slow_query' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/sql/slow.log'),
            'level'  => 'warning',
            'days'   => 60,
        ],

        // ========== 保留 Laravel 默认通道 ==========

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
