<?php

namespace App\Repositories;

use App\Models\SecurityLog;
use App\Repositories\Contracts\SecurityLogRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SecurityLogRepository implements SecurityLogRepositoryInterface
{
    public function create(array $data): SecurityLog
    {
        $data['created_at'] = now();
        return SecurityLog::create($data);
    }

    public function findByUser(int $userId, int $limit = 50): Collection
    {
        return SecurityLog::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
