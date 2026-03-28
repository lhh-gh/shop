<?php

namespace App\Repositories;

use App\Models\UserToken;
use App\Repositories\Contracts\UserTokenRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class UserTokenRepository implements UserTokenRepositoryInterface
{
    public function create(array $data): UserToken
    {
        return UserToken::create($data);
    }

    public function findByToken(string $token): ?UserToken
    {
        return UserToken::where('token', $token)->first();
    }

    public function deleteByUserAndPlatform(int $userId, string $platform): bool
    {
        return UserToken::where('user_id', $userId)
            ->where('platform', $platform)
            ->delete() > 0;
    }

    public function findActiveByUser(int $userId): Collection
    {
        return UserToken::where('user_id', $userId)
            ->where('expires_at', '>', now())
            ->orderBy('last_active_at', 'desc')
            ->get();
    }
}
