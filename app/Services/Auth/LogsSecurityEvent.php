<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Log;

trait LogsSecurityEvent
{
    protected function logSecurityEvent(string $event, array $context = []): void
    {
        Log::channel('security')->notice("Security: {$event}", array_merge([
            'user_id'    => auth()->id(),
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
            'platform'   => request()->header('X-Platform', 'unknown'),
            'timestamp'  => now()->toISOString(),
        ], $context));
    }
}
