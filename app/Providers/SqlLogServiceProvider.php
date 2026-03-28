<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Events\QueryExecuted;

class SqlLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        DB::listen(function (QueryExecuted $query) {
            $sql        = $query->sql;
            $bindings   = $query->bindings;
            $time       = $query->time; // 毫秒
            $connection = $query->connectionName;

            // 第一道防线：全量 SQL 日志（仅开发环境）
            if (config('app.debug')) {
                Log::channel('sql')->debug('SQL', [
                    'sql'        => $this->formatSql($sql, $bindings),
                    'time'       => $time . 'ms',
                    'connection' => $connection,
                ]);
            }

            // 第二道防线：慢查询日志（所有环境）
            $slowThreshold = config('database.slow_query_threshold', 1000);

            if ($time >= $slowThreshold) {
                $caller = $this->getCallerInfo();

                Log::channel('slow_query')->warning('Slow Query Detected', [
                    'sql'        => $this->formatSql($sql, $bindings),
                    'time'       => $time . 'ms',
                    'threshold'  => $slowThreshold . 'ms',
                    'connection' => $connection,
                    'caller'     => $caller,
                    'request_id' => request()->header('X-Request-Id'),
                    'url'        => request()->fullUrl(),
                    'user_id'    => auth()->id(),
                ]);
            }

            // 第三道防线：N+1 检测（仅开发环境）
            if (config('app.debug')) {
                $this->detectNPlusOne($sql, $time);
            }
        });
    }

    protected function formatSql(string $sql, array $bindings): string
    {
        foreach ($bindings as $binding) {
            $value = match (true) {
                is_null($binding)                       => 'NULL',
                is_bool($binding)                       => $binding ? 'TRUE' : 'FALSE',
                is_int($binding), is_float($binding)    => (string) $binding,
                default                                 => "'" . addslashes((string) $binding) . "'",
            };
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }
        return $sql;
    }

    protected function getCallerInfo(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (str_contains($file, '/vendor/') || str_contains($file, 'SqlLogServiceProvider')) {
                continue;
            }
            if (str_starts_with($file, app_path()) || str_starts_with($file, base_path('app'))) {
                return ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
            }
        }

        return 'unknown';
    }

    protected function detectNPlusOne(string $sql, float $time): void
    {
        $pattern = preg_replace('/\b\d+\b/', '?', $sql);
        $pattern = preg_replace("/'.+?'/", '?', $pattern);

        static $queryPatterns = [];

        if (!isset($queryPatterns[$pattern])) {
            $queryPatterns[$pattern] = ['count' => 0, 'total_time' => 0];
        }
        $queryPatterns[$pattern]['count']++;
        $queryPatterns[$pattern]['total_time'] += $time;

        $threshold = config('database.n_plus_one_threshold', 5);

        if ($threshold > 0 && $queryPatterns[$pattern]['count'] === $threshold) {
            Log::channel('slow_query')->warning('N+1 Query Detected', [
                'pattern'    => $pattern,
                'count'      => $queryPatterns[$pattern]['count'],
                'total_time' => round($queryPatterns[$pattern]['total_time'], 2) . 'ms',
                'suggestion' => '请检查是否遗漏了 with() 预加载',
                'url'        => request()->fullUrl(),
                'request_id' => request()->header('X-Request-Id'),
            ]);
        }
    }
}
