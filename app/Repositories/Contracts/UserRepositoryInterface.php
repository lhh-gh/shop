<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface UserRepositoryInterface
{
    public function findByPhone(string $phone): ?User;

    public function findByEmail(string $email): ?User;

    public function create(array $data): User;

    public function updateStatus(int $userId, int $status): bool;
}
