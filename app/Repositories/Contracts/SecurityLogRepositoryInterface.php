<?php

namespace App\Repositories\Contracts;

use App\Models\SecurityLog;
use Illuminate\Database\Eloquent\Collection;

interface SecurityLogRepositoryInterface
{
    public function create(array $data): SecurityLog;

    public function findByUser(int $userId, int $limit = 50): Collection;
}
