<?php

namespace App\Repositories\Contracts;

use App\Models\UserToken;
use Illuminate\Database\Eloquent\Collection;

interface UserTokenRepositoryInterface
{
    public function create(array $data): UserToken;

    public function findByToken(string $token): ?UserToken;

    public function deleteByUserAndPlatform(int $userId, string $platform): bool;

    public function findActiveByUser(int $userId): Collection;
}
