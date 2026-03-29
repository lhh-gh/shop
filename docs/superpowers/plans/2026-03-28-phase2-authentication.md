# Phase 2: 用户认证与授权系统

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 构建完整的用户认证基础设施，包括 JWT + Refresh Token 双令牌机制、多平台登录、设备管理和安全特性。

**Architecture:**
- Access Token: JWT (HS256), 2小时有效期，自包含 user_id/platform
- Refresh Token: 随机字符串 SHA256 哈希存储，30天有效期
- 多平台支持: app, mini_program, h5, pc
- 同平台设备互踢: 新登录踢掉旧设备（加入黑名单）
- Token 泄露检测: IP + User-Agent 指纹对比

**Tech Stack:** Laravel 12, PHP 8.2+, JWT (php-open-source-saver/jwt-auth), Redis (黑名单+验证码), PHPUnit 11

---

## Task 1: 数据库迁移 - 用户相关表

**Files:**
- Create: `database/migrations/2026_03_28_000001_create_users_table.php`
- Create: `database/migrations/2026_03_28_000002_create_user_tokens_table.php`
- Create: `database/migrations/2026_03_28_000003_create_user_social_accounts_table.php`

- [ ] **Step 1: 创建 users 表迁移**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 11)->unique()->nullable()->comment('手机号');
            $table->string('email')->unique()->nullable()->comment('邮箱');
            $table->string('password')->nullable()->comment('密码');
            $table->string('nickname', 50)->nullable()->comment('昵称');
            $table->string('avatar')->nullable()->comment('头像URL');
            $table->tinyInteger('status')->default(1)->comment('状态: 1正常 0禁用');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

- [ ] **Step 2: 创建 user_tokens 表迁移**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->string('platform', 20)->comment('平台: app/mini_program/h5/pc');
            $table->string('token_hash', 64)->comment('Refresh Token SHA256哈希');
            $table->string('jti_claim', 32)->comment('JWT jti声明（用于黑名单）');
            $table->string('client_ip', 45)->nullable()->comment('客户端IP');
            $table->string('user_agent')->nullable()->comment('User-Agent');
            $table->string('device_name', 100)->nullable()->comment('设备名称');
            $table->timestamp('last_active_at')->nullable()->comment('最后活跃时间');
            $table->timestamp('expires_at')->comment('过期时间');
            $table->timestamps();

            $table->index(['user_id', 'platform']);
            $table->index('token_hash');
            $table->index('expires_at');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tokens');
    }
};
```

- [ ] **Step 3: 创建 user_social_accounts 表迁移**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_social_accounts', function (Blueprint $table) {
            $table->id();
