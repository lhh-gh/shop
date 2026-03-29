<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 用户模型工厂
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * 当前工厂使用的密码缓存
     */
    protected static ?string $password;

    /**
     * 定义模型默认状态
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phone = '1' . fake()->numerify('##########');

        return [
            'name'              => fake()->name(),
            'phone'             => $phone,
            'email'             => fake()->unique()->safeEmail(),
            'password'          => static::$password ??= Hash::make('password'),
            'nickname'          => '用户' . substr($phone, -4),
            'avatar'            => null,
            'status'            => 1,
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
            'remember_token'    => Str::random(10),
        ];
    }

    /**
     * 标记用户为未验证状态
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_verified_at' => null,
            'email_verified_at' => null,
        ]);
    }

    /**
     * 标记用户为已禁用状态
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 0,
        ]);
    }
}
