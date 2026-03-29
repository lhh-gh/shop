# Phase 2: User Authentication & Authorization - Complete Implementation Plan

**Created:** 2026-03-28
**Duration:** 8-12 hours
**Prerequisites:** Phase 1 (Infrastructure) completed

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Implementation Roadmap](#implementation-roadmap)
4. [Detailed Steps](#detailed-steps)
5. [Testing Strategy](#testing-strategy)
6. [API Endpoints](#api-endpoints)
7. [Configuration](#configuration)

---

## Overview

### Goals

Implement complete JWT + Refresh Token authentication with:
- **Dual-token mechanism:** JWT (2h) + Refresh Token (30d)
- **Multi-platform:** app, mini_program, h5, pc
- **Device management:** Same-platform kicking, device list
- **Security:** Token leak detection, JWT blacklist, rate limiting
- **Login methods:** SMS, Password, WeChat, Alipay
- **Audit trail:** Security event logging

### Key Design Principles

1. **TDD:** Write test → see fail → implement → see pass
2. **Security layers:** Signature → Blacklist → Leak detection
3. **Platform-aware:** PC (strict) vs Mobile (flexible)
4. **Graceful degradation:** Works even if Redis is down
5. **Complete audit:** All security events logged

---

## Architecture

### Token Flow

```
┌─────────────────────────────────────────────────┐
│ 1. Login                                        │
│    → Verify credentials                         │
│    → Kick same-platform devices                 │
│    → Issue JWT (2h) + Refresh Token (30d)      │
└─────────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────────┐
│ 2. API Request                                  │
│    → Parse JWT from Authorization header        │
│    → Verify signature (HMAC-SHA256)            │
│    → Check expiry                               │
│    → Check blacklist (Redis)                    │
│    → Optional: Check user status (MySQL)        │
└─────────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────────┐
│ 3. Token Expires (after 2h)                    │
│    → Client receives 401 (code: 40101)         │
│    → Client calls /auth/refresh                 │
└─────────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────────┐
│ 4. Refresh Token                                │
│    → Verify refresh token (SHA256 hash lookup) │
│    → Leak detection (IP + UA fingerprint)      │
│    → Issue new JWT + new Refresh Token         │
│    → Rotate: old refresh token deleted         │
└─────────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────────┐
│ 5. Device Kick                                  │
│    → Add JWT jti to blacklist (Redis)          │
│    → Delete refresh token (MySQL)              │
│    → Next request: 401 (code: 40104)           │
└─────────────────────────────────────────────────┘
```

### Database Schema

**users** (extend existing):
```sql
id, phone (unique), email (nullable), password (nullable)
nickname, avatar, status (1=active, 0=disabled)
phone_verified_at, email_verified_at
created_at, updated_at
```

**user_tokens**:
```sql
id, user_id, platform (app/mini_program/h5/pc)
token (SHA256 hashed, 64 chars)
device_name, client_ip, user_agent
last_jwt_jti (for blacklist), last_active_at
expires_at, created_at, updated_at
INDEX: (user_id, platform)
```

**user_social_accounts**:
```sql
id, user_id, platform (wechat_app/wechat_mini/alipay)
platform_id (openid), union_id
nickname, avatar
access_token, refresh_token, expires_at
created_at, updated_at
UNIQUE: (user_id, platform), (platform, platform_id)
```

**security_logs**:
```sql
id, user_id, event (login/logout/token_leak/device_kicked)
detail (JSON), ip, user_agent, created_at
INDEX: (user_id, created_at)
```

### Middleware Stack

```
Request
  ↓
[JwtAuthenticate]
  - Extract JWT from Authorization header
  - Verify signature (HMAC-SHA256)
  - Check expiry
  - Inject user_id, platform into request
  ↓
[JwtBlacklist]
  - Check Redis: jwt_blacklist:{jti}
  - If exists: 401 (40104) "Device kicked"
  ↓
[OptionalDatabaseCheck]
  - Query user status from MySQL
  - If disabled: 401 (40105) "Account disabled"
  - If DB error: Skip check, log warning
  ↓
[TokenLeakDetection] (refresh endpoint only)
  - Compare current IP/UA with stored
  - PC: IP OR UA mismatch → leak
  - Mobile: IP AND UA mismatch → leak
  ↓
[PlatformIdentify]
  - Extract X-Platform header
  - Validate: app|mini_program|h5|pc
  ↓
Controller
```

---

## Implementation Roadmap

### Phase 2.1: Foundation (2-3 hours)
- **Step 1:** Install JWT library (5 min)
- **Step 2:** Database migrations (15 min)
- **Step 3:** Models & Repositories (20 min)
- **Step 4:** JWT Service (30 min)
- **Step 5:** Data Masker utility (15 min)

### Phase 2.2: Core Services (2-3 hours)
- **Step 6:** Auth Service (login, refresh, logout) (45 min)
- **Step 7:** Device Service (kick, list) (30 min)
- **Step 8:** SMS Service (send, verify) (30 min)
- **Step 9:** Repositories (User, UserToken, SecurityLog) (30 min)

### Phase 2.3: Middleware & Controllers (2-3 hours)
- **Step 10:** Middleware (JWT, Blacklist, DB Check) (45 min)
- **Step 11:** Auth Controller (SMS, Password login) (45 min)
- **Step 12:** Token Controller (refresh) (30 min)
- **Step 13:** Device Controller (list, kick) (30 min)

### Phase 2.4: Testing & Polish (1-2 hours)
- **Step 14:** Unit tests (Services) (30 min)
- **Step 15:** Feature tests (Login, Refresh, Kick) (45 min)
- **Step 16:** Integration tests (End-to-end flows) (30 min)

---

## Detailed Steps

### Step 1: Install JWT Library (5 min)

**Commands:**
```bash
# Install package
composer require php-open-source-saver/jwt-auth

# Publish config
php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"

# Generate secret
php artisan jwt:secret
```

**Expected output:**
```
jwt-auth secret [base64:xxxxx] set successfully.
```

**Verify `.env`:**
```
JWT_SECRET=base64:xxxxx
```

**Update `.env.example`:**
```
JWT_SECRET=
```

**Commit:**
```bash
git add composer.json composer.lock config/jwt.php .env.example
git commit -m "feat(auth): install JWT library

Co-Authored-By: Claude Sonnet 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Step 2: Database Migrations (15 min)

**Create migrations:**
```bash
php artisan make:migration extend_users_table
php artisan make:migration create_user_tokens_table
php artisan make:migration create_user_social_accounts_table
php artisan make:migration create_security_logs_table
```

**File: `database/migrations/2026_03_28_100000_extend_users_table.php`**

See full migration code in the appendix (too long for inline).

**Run migrations:**
```bash
php artisan migrate
```

**Verify:**
```bash
php artisan db:show
```

**Commit:**
```bash
git add database/migrations/
git commit -m "feat(auth): add database schema

- Extend users: phone, nickname, avatar, status
- Add user_tokens: refresh token storage
- Add user_social_accounts: OAuth bindings
- Add security_logs: audit trail

Co-Authored-By: Claude Sonnet 4.6 (1M context) <noreply@anthropic.com>"
```

---

